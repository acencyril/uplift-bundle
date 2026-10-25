<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Report;

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
    public const VERSION = '0.1.0';

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
        file_put_contents($dir.'/report.json', json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
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
        file_put_contents($file, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");

        return $id;
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
