<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Apply;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Composer\ConstraintRewriter;
use Acencyril\UpliftBundle\Composer\JsonEditor;
use Acencyril\UpliftBundle\Level;
use Acencyril\UpliftBundle\Plan\Move;
use Acencyril\UpliftBundle\Plan\Plan;
use Acencyril\UpliftBundle\Runner\Runner;
use Acencyril\UpliftBundle\Semver\Version;

/**
 * Does the moves the policy allows, in the working tree: rewrites composer.json where a
 * move goes past a constraint, runs composer update for the moved packages only, then
 * Rector when the project has it, then the test command.
 *
 * It never pushes and never deploys. With a branch it switches to it first; with
 * --commit it commits there. What happens next (a sandbox, a review, CI) is the
 * project's.
 *
 * If composer cannot resolve every move together, composer.json and composer.lock are
 * put back, and the moves that need no constraint change are tried alone.
 */
final class Applier
{
    public function __construct(private readonly Runner $runner, private readonly string $composer = 'composer')
    {
    }

    /** @param array{branch?: ?string, commit?: bool, rector?: bool} $options */
    public function apply(ComposerProject $project, Plan $plan, array $options = []): ApplyResult
    {
        $dir = $project->dir;
        if (!$plan->policy->enabled) {
            return new ApplyResult('disabled');
        }
        $moves = $plan->toApply();
        if ([] === $moves) {
            return new ApplyResult('nothing_to_do');
        }
        $result = new ApplyResult();

        $branch = $options['branch'] ?? null;
        $commit = (bool) ($options['commit'] ?? false);
        if (null !== $branch && (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $branch) || str_contains($branch, '..') || str_ends_with($branch, '.lock'))) {
            $result->status = 'refused';
            $result->notes[] = sprintf('"%s" is not a valid branch name.', $branch);

            return $result;
        }
        if ($commit) {
            $status = $this->runner->run(['git', 'status', '--porcelain'], $dir, 60);
            if (!$status->ok() || '' !== trim($status->output)) {
                $result->status = 'refused';
                $result->notes[] = $status->ok() ? 'The working tree has uncommitted changes: --commit needs a clean tree.' : 'Not a git repository: --commit needs one.';

                return $result;
            }
        }
        if (null !== $branch) {
            $switch = $this->runner->run(['git', 'switch', '-c', $branch], $dir, 60);
            $result->step('branch', $switch->ok() ? 'done' : 'failed', 'git switch -c '.$branch, $switch->exitCode, $switch->tail(20));
            if (!$switch->ok()) {
                $result->status = 'refused';
                $result->notes[] = sprintf('Could not create the branch %s (it may exist already).', $branch);

                return $result;
            }
            $result->branch = $branch;
        }

        $jsonFile = $dir.'/composer.json';
        $lockFile = $dir.'/composer.lock';
        $json = $project->text();
        $lock = is_file($lockFile) ? (string) file_get_contents($lockFile) : null;

        $ok = $this->attempt($project, $moves, $result);
        if (!$ok) {
            $this->restore($jsonFile, $json, $lockFile, $lock);
            $simple = array_values(array_filter($moves, static fn (Move $m) => !$m->rewrites() && ('php' !== $m->category || Level::Patch === $m->level)));
            if (\count($simple) < \count($moves) && [] !== $simple) {
                $result->notes[] = 'Composer could not resolve every move together: trying the moves that keep the constraints.';
                $result->rewrites = [];
                $ok = $this->attempt($project, $simple, $result);
                if ($ok) {
                    $result->dropped = array_values(array_filter($moves, static fn (Move $m) => !\in_array($m, $simple, true)));
                    $moves = $simple;
                } else {
                    $this->restore($jsonFile, $json, $lockFile, $lock);
                }
            }
        }
        if (!$ok) {
            $result->status = 'composer_failed';
            $result->rewrites = [];
            $result->notes[] = 'composer.json and composer.lock are back as they were.';

            return $result;
        }
        $result->applied = $moves;

        if (($options['rector'] ?? true) && is_file($dir.'/vendor/bin/rector') && is_file($dir.'/rector.php')) {
            $rector = $this->runner->run(['vendor/bin/rector', 'process', '--no-progress-bar', '--no-ansi'], $dir, 1800);
            $result->step('rector', $rector->ok() ? 'done' : 'failed', 'vendor/bin/rector process', $rector->exitCode, $rector->tail());
            if (!$rector->ok()) {
                $result->notes[] = 'Rector failed (see its output in the report): the code was not rewritten for the new versions. Check rector.php.';
            }
        } else {
            $result->step('rector', 'skipped');
        }

        $test = $plan->policy->testCommand;
        if ('' === $test) {
            $result->status = 'untested';
            $result->step('tests', 'not_run');
            $result->notes[] = 'No UPLIFT_TEST_COMMAND: nothing says this upgrade works.';
        } else {
            $run = $this->runner->run($test, $dir, 3600);
            $result->step('tests', $run->ok() ? 'passed' : 'failed', $test, $run->exitCode, $run->tail());
            $result->status = $run->ok() ? 'passed' : 'tests_failed';
        }
        if (null !== $plan->phpAfter && Version::parse(preg_replace('/[-+].*$/', '', \PHP_VERSION) ?? '')?->branch() !== $plan->phpAfter->branch()) {
            $result->notes[] = sprintf('The project targets PHP %s and these steps ran on PHP %s: run the tests on the target PHP too (and update the runtime image).', $plan->phpAfter->branch(), \PHP_VERSION);
        }

        if ($commit) {
            $add = $this->runner->run(['git', 'add', '-A'], $dir, 60);
            $message = 'Uplift: '.implode(', ', array_map(static fn (Move $m) => $m->package.' '.$m->to->format(), $moves))."\n\nTests: ".$result->status;
            $done = $add->ok() ? $this->runner->run(['git', 'commit', '-q', '-m', $message], $dir, 60) : $add;
            $result->step('commit', $done->ok() ? 'done' : 'failed', 'git commit', $done->exitCode, $done->tail(20));
            if ($done->ok()) {
                $head = $this->runner->run(['git', 'rev-parse', '--short', 'HEAD'], $dir, 60);
                $result->commit = $head->ok() ? trim($head->output) : null;
            }
        }

        return $result;
    }

    /** @param list<Move> $moves */
    private function attempt(ComposerProject $project, array $moves, ApplyResult $result): bool
    {
        $text = $project->text();
        $names = [];
        $with = [];
        foreach ($moves as $m) {
            if ('php' === $m->category) {
                $text = $this->rewritePhp($text, $project, $m, $result);
                continue;
            }
            $packages = 'symfony' === $m->category ? $m->members : [$m->package];
            if ($m->rewrites()) {
                foreach ($packages as $p) {
                    $text = $this->set($text, [(string) $project->section($p), $p], (string) $m->constraintTo, $result);
                }
                if ('symfony' === $m->category && $project->symfonyRequire() === $m->constraintFrom) {
                    $text = $this->set($text, ['extra', 'symfony', 'require'], (string) $m->constraintTo, $result);
                }
            }
            array_push($names, ...$packages);
            if ('symfony' === $m->category) {
                // The components the project does not require itself (symfony/doctrine-bridge
                // through doctrine-bundle, symfony/http-client through a bundle…) are on the
                // same release line: they move with it, or they stay behind on the old minor.
                foreach ($project->lockedVersions() as $name => $version) {
                    if (str_starts_with($name, 'symfony/') && !\in_array($name, $names, true) && Version::parse($version)?->branch() === $m->from->branch()) {
                        $names[] = $name;
                    }
                }
            }
            if ('symfony' !== $m->category) {
                $with[] = $m->package.':'.$m->to->format();
            }
        }
        file_put_contents($project->dir.'/composer.json', $text);

        if ([] === $names) {
            $cmd = [$this->composer, 'update', '--lock', '--no-interaction', '--no-progress'];
        } else {
            $cmd = [$this->composer, 'update', ...$names];
            foreach ($with as $w) {
                array_push($cmd, '--with', $w);
            }
            array_push($cmd, '--with-dependencies', '--no-interaction', '--no-progress');
        }
        $run = $this->runner->run($cmd, $project->dir, 1800);
        $result->step('composer', $run->ok() ? 'done' : 'failed', implode(' ', $cmd), $run->exitCode, $run->tail());

        return $run->ok();
    }

    private function rewritePhp(string $text, ComposerProject $project, Move $m, ApplyResult $result): string
    {
        $before = $text;
        $platform = $project->platformPhp();
        if (null !== $platform && null !== ($v = Version::parse($platform))) {
            $new = $m->to->format($v->segments);
            if ($new !== $platform) {
                $text = $this->set($text, ['config', 'platform', 'php'], $new, $result);
            }
        }
        $require = $project->requirePhp();
        if (Level::Patch !== $m->level && null !== $require) {
            $raised = ConstraintRewriter::raisePhp($require, $m->to);
            if (null === $raised) {
                $result->notes[] = sprintf('require.php "%s" was left as is: raise it to PHP %s by hand.', $require, $m->to->branch());
            } elseif ($raised !== $require) {
                $text = $this->set($text, ['require', 'php'], $raised, $result);
            }
        }
        if ($before === $text && Level::Patch === $m->level) {
            $result->notes[] = sprintf('PHP %s: nothing to change in composer.json, the runtime image picks the release up.', $m->to);
        }

        return $text;
    }

    /** @param list<string> $path */
    private function set(string $text, array $path, string $value, ApplyResult $result): string
    {
        $span = JsonEditor::find($text, $path);
        $old = null !== $span ? json_decode(substr($text, $span[0], $span[1] - $span[0])) : null;
        $new = JsonEditor::replace($text, $path, $value);
        if (null === $new) {
            $result->notes[] = sprintf('composer.json: %s not found, left as is.', implode('.', $path));

            return $text;
        }
        $result->rewrites[] = sprintf('%s: %s -> %s', implode('.', $path), \is_string($old) ? $old : '?', $value);

        return $new;
    }

    private function restore(string $jsonFile, string $json, string $lockFile, ?string $lock): void
    {
        file_put_contents($jsonFile, $json);
        if (null !== $lock) {
            file_put_contents($lockFile, $lock);
        } elseif (is_file($lockFile)) {
            unlink($lockFile);
        }
    }
}
