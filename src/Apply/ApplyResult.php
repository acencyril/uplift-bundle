<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Apply;

use Acencyril\UpliftBundle\Plan\Move;

/**
 * What uplift:apply did.
 *
 * status: disabled        UPLIFT_ENABLED is not 1, nothing was touched
 *         nothing_to_do   no move allowed by the policy
 *         refused         the working tree was not clean (with --commit) or the branch is not valid
 *         composer_failed composer could not resolve the moves; composer.json and composer.lock are restored
 *         tests_failed    the moves are in place, the test command failed
 *         untested        the moves are in place, no test command
 *         passed          the moves are in place and the test command passed
 */
final class ApplyResult
{
    /** @var list<Move> */
    public array $applied = [];
    /** @var list<Move> moves the policy allowed but that were left out (composer refused them together) */
    public array $dropped = [];
    /** @var array<string, array{status: string, command: string, exit_code: ?int, output: string}> */
    public array $steps = [];
    /** @var list<string> */
    public array $notes = [];
    /** @var list<string> the composer.json values that changed, "path: old -> new" */
    public array $rewrites = [];
    /** @var array<string, array{from: ?string, to: ?string}>|null the packages whose locked version changed (from/to null: not in that lock); null without a composer.lock */
    public ?array $lockChanges = null;
    /** @var array{rules: list<string>, files: list<string>} what Rector rewrote (rules applied, files changed) */
    public array $rector = ['rules' => [], 'files' => []];
    public ?string $branch = null;
    public ?string $commit = null;

    public function __construct(public string $status = 'nothing_to_do')
    {
    }

    public function step(string $name, string $status, string $command = '', ?int $exitCode = null, string $output = ''): void
    {
        $this->steps[$name] = ['status' => $status, 'command' => $command, 'exit_code' => $exitCode, 'output' => $output];
    }

    public function changed(): bool
    {
        return \in_array($this->status, ['tests_failed', 'untested', 'passed'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'applied' => array_map(static fn (Move $m) => $m->toArray(), $this->applied),
            'dropped' => array_map(static fn (Move $m) => $m->toArray(), $this->dropped),
            'rewrites' => $this->rewrites,
            'lock_changes' => $this->lockChanges,
            'rector' => $this->rector,
            'steps' => $this->steps,
            'branch' => $this->branch,
            'commit' => $this->commit,
            'notes' => $this->notes,
        ];
    }
}
