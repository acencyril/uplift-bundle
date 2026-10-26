<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/**
 * What "uplift:adopt --apply" did.
 *
 * status: off       UPLIFT_ADOPT=off
 *         proposed  nothing was rewritten (UPLIFT_ADOPT=propose, no Rector, nothing to apply)
 *         done      the rewrites ran; each adoption has its own outcome
 *
 * Outcome of an adoption: applied        rewritten, the tests passed
 *                         untested       rewritten, no test command
 *                         reverted       rewritten, the tests failed: its files are back as they were
 *                         rector_failed  Rector failed: its files are back as they were
 *                         unchanged      Rector changed nothing
 */
final class AdoptionResult
{
    /** @var array<string, array{status: string, files: list<string>, steps: array<string, array{status: string, command: string, exit_code: ?int, output: string}>}> */
    public array $outcomes = [];
    /** @var list<string> */
    public array $notes = [];
    public ?string $commit = null;

    public function __construct(public string $status = 'proposed')
    {
    }

    /** @param list<string> $files */
    public function outcome(string $id, string $status, array $files = []): void
    {
        $this->outcomes[$id] = ['status' => $status, 'files' => $files, 'steps' => $this->outcomes[$id]['steps'] ?? []];
    }

    public function step(string $id, string $name, string $status, string $command = '', ?int $exitCode = null, string $output = ''): void
    {
        $this->outcomes[$id] ??= ['status' => 'running', 'files' => [], 'steps' => []];
        $this->outcomes[$id]['steps'][$name] = ['status' => $status, 'command' => $command, 'exit_code' => $exitCode, 'output' => $output];
    }

    /** @return list<string> the adoptions now in the code */
    public function kept(): array
    {
        return array_keys(array_filter($this->outcomes, static fn (array $o) => \in_array($o['status'], ['applied', 'untested'], true)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'outcomes' => $this->outcomes, 'commit' => $this->commit, 'notes' => $this->notes];
    }
}
