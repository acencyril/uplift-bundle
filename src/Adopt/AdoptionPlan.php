<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

use Acencyril\UpliftBundle\Policy;

/** The new features this site can use, with their candidate places. */
final class AdoptionPlan
{
    /**
     * @param list<Finding> $findings
     * @param list<string>  $notes
     */
    public function __construct(
        public readonly Policy $policy,
        public readonly array $findings,
        public readonly array $notes = [],
        public readonly int $files = 0,
    ) {
    }

    /** @return list<Finding> */
    public function withDecision(string ...$decisions): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f) => \in_array($f->decision, $decisions, true)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy' => ['adopt' => $this->policy->adopt, 'skip' => $this->policy->adoptSkip],
            'files_scanned' => $this->files,
            'findings' => array_map(static fn (Finding $f) => $f->toArray(), $this->findings),
            'notes' => $this->notes,
        ];
    }
}
