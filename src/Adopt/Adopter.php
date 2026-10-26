<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Runner\Runner;
use Acencyril\UpliftBundle\Semver\Version;

/**
 * Finds the new features the site can use (the installed versions decide), with their
 * candidate places; with UPLIFT_ADOPT=apply, rewrites them with Rector, one adoption at a
 * time, each followed by the test command. An adoption whose tests fail is put back.
 *
 * It never pushes and never deploys. Only the candidate files of an adoption are given
 * to Rector, and only those are put back.
 */
final class Adopter
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Scanner $scanner,
        private readonly Runner $runner,
        private readonly Texts $texts = new Texts(),
    ) {
    }

    /** @param list<string> $paths what to scan, relative to the project */
    public function find(ComposerProject $project, Policy $policy, array $paths = ['src'], string $locale = 'en', string $runtimePhp = \PHP_VERSION): AdoptionPlan
    {
        if ('off' === $policy->adopt) {
            return new AdoptionPlan($policy, [], ['UPLIFT_ADOPT=off: new features are not looked for.']);
        }
        $findings = [];
        $scan = [];
        foreach ($this->catalog->all() as $a) {
            $title = $this->texts->title($a, $locale);
            $summary = $this->texts->summary($a, $locale);
            if (\in_array($a->id(), $policy->adoptSkip, true)) {
                $findings[$a->id()] = new Finding($a, 'skipped', $title, $summary, reason: 'in UPLIFT_ADOPT_SKIP');
                continue;
            }
            if ($a instanceof OptIn && !\in_array($a->id(), $policy->adoptOptIn, true)) {
                $findings[$a->id()] = new Finding($a, 'skipped', $title, $summary, reason: sprintf('off by default: opt in with UPLIFT_ADOPT_OPT_IN=%s', $a->id()));
                continue;
            }
            $installed = 'php' === $a->package() ? $project->phpVersion($runtimePhp) : $project->installed($a->package());
            $min = Version::of($a->minVersion());
            if (null === $installed) {
                $findings[$a->id()] = new Finding($a, 'later', $title, $summary, reason: sprintf('needs %s >= %s, not installed', $a->package(), $a->minVersion()));
            } elseif ($min->greaterThan($installed)) {
                $findings[$a->id()] = new Finding($a, 'later', $title, $summary, (string) $installed, reason: sprintf('needs %s >= %s, installed %s', $a->package(), $a->minVersion(), $installed));
            } else {
                $findings[$a->id()] = new Finding($a, 'none', $title, $summary, (string) $installed);
                $scan[] = $a;
            }
        }

        $result = $this->scanner->scan($project->dir, $paths, $scan);
        foreach ($scan as $a) {
            $f = $findings[$a->id()];
            $candidates = $result['candidates'][$a->id()] ?? [];
            [$decision, $reason] = match (true) {
                [] === $candidates => ['none', 'nothing found to rewrite'],
                $a->experimental() && !$a instanceof OptIn => ['propose', 'experimental: the API is to be confirmed, never applied'],
                null === $a->rector() => ['manual', 'no automatic rewrite: a person makes the change'],
                'apply' === $policy->adopt => ['apply', 'Rector rewrites it, then the tests decide'],
                default => ['propose', 'UPLIFT_ADOPT=propose: a person decides'],
            };
            $findings[$a->id()] = new Finding($a, $decision, $f->title, $f->summary, $f->installed, $candidates, $reason);
        }

        return new AdoptionPlan($policy, array_values($findings), $result['errors'], $result['files']);
    }

    /** @param array{commit?: bool} $options */
    public function apply(ComposerProject $project, AdoptionPlan $plan, array $options = []): AdoptionResult
    {
        $dir = $project->dir;
        if ('off' === $plan->policy->adopt) {
            return new AdoptionResult('off');
        }
        $result = new AdoptionResult('proposed');
        $todo = $plan->withDecision('apply');
        if ('apply' !== $plan->policy->adopt) {
            $result->notes[] = 'UPLIFT_ADOPT=propose: nothing rewritten.';

            return $result;
        }
        if ([] === $todo) {
            return $result;
        }
        if (!is_file($dir.'/vendor/bin/rector')) {
            $result->notes[] = 'Rector is not installed (vendor/bin/rector): nothing rewritten. composer require --dev rector/rector';

            return $result;
        }
        $result->status = 'done';
        $test = $plan->policy->testCommand;

        foreach ($todo as $finding) {
            $id = $finding->id();
            $files = $finding->files();
            $before = [];
            foreach ($files as $f) {
                $before[$f] = (string) file_get_contents($dir.'/'.$f);
            }
            $config = $this->rectorConfig($dir, $files, (string) $finding->adoption->rector(), $id);
            $rector = $this->runner->run(['vendor/bin/rector', 'process', '--config='.$config, '--no-progress-bar', '--no-ansi'], $dir, 1800);
            @unlink($config);
            $result->step($id, 'rector', $rector->ok() ? 'done' : 'failed', 'vendor/bin/rector process ('.$finding->adoption->rector().')', $rector->exitCode, $rector->tail(20));
            if (!$rector->ok()) {
                $this->restore($dir, $before);
                $result->outcome($id, 'rector_failed');
                continue;
            }
            $changed = array_values(array_filter($files, static fn (string $f) => (string) @file_get_contents($dir.'/'.$f) !== $before[$f]));
            if ([] === $changed) {
                $result->outcome($id, 'unchanged');
                continue;
            }
            if ('' === $test) {
                $result->step($id, 'tests', 'not_run');
                $result->outcome($id, 'untested', $changed);
                continue;
            }
            $run = $this->runner->run($test, $dir, 3600);
            $result->step($id, 'tests', $run->ok() ? 'passed' : 'failed', $test, $run->exitCode, $run->tail());
            if ($run->ok()) {
                $result->outcome($id, 'applied', $changed);
            } else {
                $this->restore($dir, $before);
                $result->outcome($id, 'reverted', $changed);
                $result->notes[] = sprintf('%s: the tests failed, %d file(s) put back.', $id, \count($changed));
            }
        }

        $kept = $result->kept();
        if (($options['commit'] ?? false) && [] !== $kept) {
            $files = [];
            foreach ($kept as $id) {
                array_push($files, ...$result->outcomes[$id]['files']);
            }
            $files = array_values(array_unique($files));
            // only these files: whatever else is in the working tree stays out of the commit
            $add = $this->runner->run(['git', 'add', '--', ...$files], $dir, 60);
            $done = $add->ok() ? $this->runner->run(['git', 'commit', '-q', '-m', 'Uplift: adopt '.implode(', ', $kept), '--', ...$files], $dir, 60) : $add;
            if ($done->ok()) {
                $head = $this->runner->run(['git', 'rev-parse', '--short', 'HEAD'], $dir, 60);
                $result->commit = $head->ok() ? trim($head->output) : null;
            } else {
                $result->notes[] = 'git commit of the adoptions failed: '.$done->tail(3);
            }
        }

        return $result;
    }

    /** @param list<string> $files */
    private function rectorConfig(string $dir, array $files, string $rule, string $id): string
    {
        $path = sys_get_temp_dir().'/uplift-adopt-'.$id.'-'.bin2hex(random_bytes(4)).'.php';
        $paths = implode(', ', array_map(static fn (string $f) => var_export($dir.'/'.$f, true), $files));
        file_put_contents($path, "<?php\n\n// Written by uplift:adopt for one run, then deleted.\nuse Rector\\Config\\RectorConfig;\n\n"
            ."return RectorConfig::configure()\n    ->withPaths([$paths])\n    ->withRules([\\".ltrim($rule, '\\')."::class]);\n");

        return $path;
    }

    /** @param array<string, string> $before */
    private function restore(string $dir, array $before): void
    {
        foreach ($before as $f => $content) {
            file_put_contents($dir.'/'.$f, $content);
        }
    }
}
