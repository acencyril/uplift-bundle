<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Report;

use Acencyril\UpliftBundle\Adopt\AdoptionPlan;
use Acencyril\UpliftBundle\Adopt\AdoptionResult;
use Acencyril\UpliftBundle\Adopt\Candidate;
use Acencyril\UpliftBundle\Adopt\Finding;
use Acencyril\UpliftBundle\Apply\ApplyResult;
use Acencyril\UpliftBundle\Plan\Move;
use Acencyril\UpliftBundle\Plan\Plan;

/**
 * The report of a run: report.json for machines (CI, Rehearsal, a morning read),
 * report.md for people. Optionally merged as one change into a Rehearsal changes file
 * (acencyril/rehearsal-bundle), so the upgrade can be tried in the site's admin.
 */
final class ReportWriter
{
    public const VERSION = '0.1.1';

    /**
     * Command output is not always valid UTF-8 (a test printing binary, a tail cut inside a
     * character): json_encode() then returned false and the report was an empty file.
     */
    public static function encode(mixed $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function data(Plan $plan, ?ApplyResult $result, \DateTimeImmutable $at, string $project = ''): array
    {
        return [
            'tool' => 'uplift',
            'version' => self::VERSION,
            'generated_at' => $at->format(\DATE_ATOM),
            'project' => $project,
            'status' => $result?->status ?? 'planned',
        ] + $plan->toArray() + ['result' => $result?->toArray()];
    }

    /** @return array{json: string, markdown: string} the files written */
    public static function write(string $dir, Plan $plan, ?ApplyResult $result, \DateTimeImmutable $at, string $project = ''): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $data = self::data($plan, $result, $at, $project);
        file_put_contents($dir.'/report.json', self::encode($data)."\n");
        file_put_contents($dir.'/report.md', self::markdown($plan, $result, $at, $project));

        return ['json' => $dir.'/report.json', 'markdown' => $dir.'/report.md'];
    }

    public static function markdown(Plan $plan, ?ApplyResult $result, \DateTimeImmutable $at, string $project = ''): string
    {
        $p = $plan->policy;
        $md = '# Uplift report'.('' !== $project ? ': '.$project : '')."\n\n";
        $md .= sprintf("%s · status **%s**\n\n", $at->format('Y-m-d H:i T'), $result?->status ?? 'planned');
        $md .= sprintf("Policy: enabled %s · PHP %s · Symfony %s · packages %s · own vendor (%s) up to %s · PHP ceiling %s\n\n",
            $p->enabled ? 'yes' : 'no', $p->php->value, $p->symfony->value, $p->packages->value,
            [] === $p->ownVendors ? 'none' : implode(', ', $p->ownVendors), $p->levelFor('own')->value, $p->phpMax ?? 'none');
        if (null !== $plan->php) {
            $md .= sprintf("PHP: %s%s\n\n", $plan->php, null !== $plan->phpAfter && (string) $plan->phpAfter !== (string) $plan->php ? ' -> '.$plan->phpAfter : '');
        }

        $applied = null !== $result && [] !== $result->applied ? $result->applied : null;
        $sections = [
            'Applied' => $applied ?? [],
            'To apply' => null === $result ? $plan->withDecision('apply') : [],
            'Allowed but left out' => $result?->dropped ?? [],
            'Proposed (a person decides)' => $plan->withDecision('propose'),
            'Blocked' => $plan->withDecision('blocked'),
        ];
        foreach ($sections as $title => $moves) {
            if ([] === $moves) {
                continue;
            }
            $md .= "## $title\n\n| Package | From | To | Level | Constraint | Why |\n|---|---|---|---|---|---|\n";
            foreach ($moves as $m) {
                $md .= sprintf("| %s | %s | %s | %s | %s | %s |\n", self::cell($m->package.([] !== $m->members ? ' ('.\count($m->members).' packages)' : '')),
                    $m->from, $m->to, $m->level->value, self::cell(self::constraint($m)), self::cell($m->reason));
            }
            $md .= "\n";
        }
        if ([] === $plan->moves) {
            $md .= "Everything is up to date.\n\n";
        }
        if (null !== $result && [] !== $result->rewrites) {
            $md .= "## composer.json\n\n".implode("\n", array_map(static fn (string $r) => '- `'.$r.'`', $result->rewrites))."\n\n";
        }
        if (null !== $result && [] !== $result->steps) {
            $md .= "## Steps\n\n";
            foreach ($result->steps as $name => $s) {
                $md .= sprintf("- **%s**: %s%s\n", $name, $s['status'], '' !== $s['command'] ? ' (`'.$s['command'].'`)' : '');
                if ('' !== trim($s['output']) && \in_array($s['status'], ['failed', 'passed'], true)) {
                    $md .= "\n<details><summary>output</summary>\n\n```\n".str_replace('```', "'''", $s['output'])."\n```\n\n</details>\n\n";
                }
            }
            $md .= "\n";
        }
        $notes = array_merge($plan->notes, $result?->notes ?? []);
        if ([] !== $notes) {
            $md .= "## Notes\n\n".implode("\n", array_map(static fn (string $n) => '- '.$n, $notes))."\n";
        }

        return rtrim($md)."\n";
    }

    /**
     * Adds (or replaces) this run as one change in a Rehearsal changes file.
     *
     * @return string the change id
     */
    public static function toRehearsal(string $file, Plan $plan, ApplyResult $result, \DateTimeImmutable $at, ?string $revision = null): string
    {
        $id = 'uplift-'.$at->format('Y-m-d');
        $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = \is_array($existing) ? $existing : ['title' => 'Maintenance', 'summary' => '', 'changes' => []];
        $changes = array_values(array_filter(\is_array($data['changes'] ?? null) ? $data['changes'] : [], static fn ($c) => !\is_array($c) || ($c['id'] ?? null) !== $id));

        $line = static fn (Move $m) => sprintf('%s %s -> %s (%s)', $m->package, $m->from, $m->to, $m->level->value);
        $list = static fn (array $moves, ?callable $suffix = null) => implode('', array_map(static fn (Move $m) => "\n- ".$line($m).(null !== $suffix ? $suffix($m) : ''), $moves));
        $detail = 'Moved:'.$list($result->applied);
        if ([] !== ($proposed = $plan->withDecision('propose'))) {
            $detail .= "\n\nProposed, not applied:".$list($proposed);
        }
        if ([] !== ($blocked = $plan->withDecision('blocked'))) {
            $detail .= "\n\nBlocked:".$list($blocked, static fn (Move $m) => ': '.$m->reason);
        }
        $tests = $result->steps['tests'] ?? ['status' => 'not_run', 'command' => '', 'output' => ''];
        $change = [
            'id' => $id,
            'title' => sprintf('Dependency upgrades (%d)', \count($result->applied)),
            'detail' => $detail,
            'replaced' => implode('; ', array_map(static fn (Move $m) => $m->package.' '.$m->from, $result->applied)),
            'check' => 'Open the main pages and the admin: everything should look and work as before.',
            'url' => '',
            'source' => 'uplift',
            'revision' => $revision ?? $result->commit ?? '',
            'tests' => [
                'status' => match ($tests['status']) { 'passed' => 'passed', 'failed' => 'failed', default => 'not_run' },
                'summary' => '' !== $tests['command'] ? $tests['command'] : 'no test command',
                'output' => mb_substr($tests['output'], -4000),
            ],
            'proposed_at' => $at->format(\DATE_ATOM),
        ];
        $changes[] = $change;
        $data['changes'] = $changes;

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o775, true);
        }
        file_put_contents($file, self::encode($data)."\n");

        return $id;
    }

    /** @return array<string, mixed> the "adoptions" entry of report.json */
    public static function adoptionsData(AdoptionPlan $plan, ?AdoptionResult $result): array
    {
        return $plan->toArray() + ['result' => $result?->toArray()];
    }

    /**
     * Puts the adoptions into report.json and report.md, next to what uplift:apply wrote
     * there (or alone when there is no report yet). A previous adoptions section is replaced.
     *
     * @return array{json: string, markdown: string}
     */
    public static function writeAdoptions(string $dir, AdoptionPlan $plan, ?AdoptionResult $result, \DateTimeImmutable $at, string $project = ''): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $json = $dir.'/report.json';
        $data = is_file($json) ? json_decode((string) file_get_contents($json), true) : null;
        if (!\is_array($data)) {
            $data = ['tool' => 'uplift', 'version' => self::VERSION, 'generated_at' => $at->format(\DATE_ATOM), 'project' => $project, 'status' => 'adoptions'];
        }
        $data['adoptions'] = self::adoptionsData($plan, $result) + ['generated_at' => $at->format(\DATE_ATOM)];
        file_put_contents($json, self::encode($data)."\n");

        $md = $dir.'/report.md';
        $text = is_file($md) ? (string) file_get_contents($md) : '# Uplift report'.('' !== $project ? ': '.$project : '')."\n\n".$at->format('Y-m-d H:i T')."\n";
        $text = preg_replace('/\n*<!-- uplift:adoptions -->.*<!-- \/uplift:adoptions -->\n?/s', "\n", $text) ?? $text;
        file_put_contents($md, rtrim($text)."\n\n".self::adoptionsMarkdown($plan, $result));

        return ['json' => $json, 'markdown' => $md];
    }

    public static function adoptionsMarkdown(AdoptionPlan $plan, ?AdoptionResult $result): string
    {
        $md = "<!-- uplift:adoptions -->\n## New features\n\n";
        $md .= sprintf("UPLIFT_ADOPT=%s · skipped: %s · %d file(s) scanned\n\n", $plan->policy->adopt, [] === $plan->policy->adoptSkip ? 'none' : implode(', ', $plan->policy->adoptSkip), $plan->files);
        foreach ($plan->withDecision(...Finding::WITH_CANDIDATES) as $f) {
            $a = $f->adoption;
            $md .= sprintf("### %s: %s\n\n", $f->title, $f->decision);
            $md .= sprintf("`%s` · %s (installed %s) · rewrite: %s · [docs](%s)%s\n\n", $f->id(), $f->requirement(), $f->installed ?? '?',
                null !== $a->rector() ? 'Rector `'.$a->rector().'`' : 'manual', $a->docs(), $a->experimental() ? ' · **experimental: not confirmed upstream, never applied**' : '');
            $md .= $f->summary."\n\n".$f->reason.".\n\n| Where | Code | Use |\n|---|---|---|\n";
            foreach ($f->candidates as $c) {
                $md .= sprintf("| `%s` | `%s` | %s |\n", self::cell($c->where()), self::cell(str_replace('`', "'", $c->snippet)), self::cell($c->hint));
            }
            $md .= "\n";
            if (null !== ($o = $result?->outcomes[$f->id()] ?? null)) {
                $md .= sprintf("**Result: %s**%s\n\n", $o['status'], [] !== $o['files'] ? ' ('.implode(', ', array_map(static fn (string $x) => '`'.$x.'`', $o['files'])).')' : '');
                foreach ($o['steps'] as $name => $s) {
                    $md .= sprintf("- **%s**: %s%s\n", $name, $s['status'], '' !== $s['command'] ? ' (`'.$s['command'].'`)' : '');
                    if ('failed' === $s['status'] && '' !== trim($s['output'])) {
                        $md .= "\n<details><summary>output</summary>\n\n```\n".str_replace('```', "'''", $s['output'])."\n```\n\n</details>\n\n";
                    }
                }
                $md .= "\n";
            }
        }
        $rest = ['none' => 'Nothing found to rewrite', 'later' => 'Later (the site does not run the version yet)', 'skipped' => 'Skipped'];
        foreach ($rest as $decision => $title) {
            if ([] !== ($list = $plan->withDecision($decision))) {
                $md .= "### $title\n\n".implode("\n", array_map(static fn (Finding $f) => sprintf('- %s (`%s`)%s', $f->title, $f->id(), 'none' === $decision ? '' : ': '.$f->reason), $list))."\n\n";
            }
        }
        $notes = array_merge($plan->notes, $result?->notes ?? []);
        if ([] !== $notes) {
            $md .= implode("\n", array_map(static fn (string $n) => '- '.$n, $notes))."\n\n";
        }
        if (null !== $result?->commit) {
            $md .= 'Commit: `'.$result->commit."`\n\n";
        }

        return rtrim($md)."\n<!-- /uplift:adoptions -->\n";
    }

    /**
     * Adds (or replaces) one Rehearsal change per adoption with candidates, so each can be
     * validated or rejected on its own. The change id is stable: uplift-adopt-<id>.
     *
     * @return list<string> the change ids
     */
    public static function adoptionsToRehearsal(string $file, AdoptionPlan $plan, ?AdoptionResult $result, \DateTimeImmutable $at, ?string $revision = null): array
    {
        $findings = $plan->withDecision(...Finding::WITH_CANDIDATES);
        if ([] === $findings) {
            return [];
        }
        $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = \is_array($existing) ? $existing : ['title' => 'Maintenance', 'summary' => '', 'changes' => []];
        $changes = \is_array($data['changes'] ?? null) ? $data['changes'] : [];
        $ids = [];
        foreach ($findings as $f) {
            $a = $f->adoption;
            $id = substr('uplift-adopt-'.$f->id(), 0, 64);
            $outcome = $result?->outcomes[$f->id()] ?? null;
            $status = $outcome['status'] ?? 'proposed';
            $tests = $outcome['steps']['tests'] ?? null;
            $places = implode('', array_map(static fn (Candidate $c) => "\n- ".$c->where().': '.$c->snippet.('' !== $c->hint ? ' -> '.$c->hint : ''), $f->candidates));
            $detail = $f->summary."\n\n".match ($status) {
                'applied' => 'Rewritten by Rector, the tests passed.',
                'untested' => 'Rewritten by Rector, no test command ran.',
                'reverted' => 'Rewritten by Rector, the tests failed: put back, to do by hand.',
                'rector_failed' => 'Rector failed: nothing changed, to do by hand.',
                'unchanged' => 'Rector changed nothing: to do by hand.',
                default => $a->experimental() ? 'Experimental: the feature is not confirmed upstream yet. Not applied.' : 'Proposed, not applied ('.$f->reason.').',
            }."\n\nPlaces (".\count($f->candidates).'):'.$places."\n\nNeeds ".$f->requirement().', installed '.($f->installed ?? '?').'. Docs: '.$a->docs();
            $change = [
                'id' => $id,
                'title' => 'New feature: '.$f->title,
                'detail' => $detail,
                'replaced' => sprintf('The hand-written version, in %d place(s)', \count($f->candidates)),
                'check' => \in_array($status, ['applied', 'untested'], true) ? 'Open the pages and run the commands these files serve: they should work as before.' : 'Decide whether to adopt it; nothing changed in the code yet.',
                'url' => '',
                'source' => 'uplift',
                'revision' => $revision ?? $result?->commit ?? '',
                'tests' => [
                    'status' => match ($tests['status'] ?? null) { 'passed' => 'passed', 'failed' => 'failed', default => 'not_run' },
                    'summary' => null !== $tests && '' !== $tests['command'] ? $tests['command'] : 'not applied, no tests',
                    'output' => mb_substr($tests['output'] ?? '', -4000),
                ],
                'proposed_at' => $at->format(\DATE_ATOM),
            ];
            $changes = array_values(array_filter($changes, static fn ($c) => !\is_array($c) || ($c['id'] ?? null) !== $id));
            $changes[] = $change;
            $ids[] = $id;
        }
        $data['changes'] = $changes;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o775, true);
        }
        file_put_contents($file, self::encode($data)."\n");

        return $ids;
    }

    private static function constraint(Move $m): string
    {
        if ('php' === $m->category) {
            return (string) $m->constraintFrom;
        }

        return $m->rewrites() ? $m->constraintFrom.' -> '.$m->constraintTo : (string) $m->constraintFrom;
    }

    private static function cell(string $s): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $s);
    }
}
