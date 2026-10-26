<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/**
 * One adoption, looked at for this site.
 *
 * decision: "apply"    candidates found, a Rector rule, UPLIFT_ADOPT=apply: --apply rewrites them
 *           "propose"  candidates found, a Rector rule (or experimental): a person decides
 *           "manual"   candidates found, no automatic rewrite: a person makes the change
 *           "none"     the site can use the feature, nothing found to rewrite
 *           "later"    the site does not run the version yet (reason says which)
 *           "skipped"  listed in UPLIFT_ADOPT_SKIP, or an opt-in adoption not in UPLIFT_ADOPT_OPT_IN
 */
final class Finding
{
    public const WITH_CANDIDATES = ['apply', 'propose', 'manual'];

    /** @param list<Candidate> $candidates */
    public function __construct(
        public readonly Adoption $adoption,
        public readonly string $decision,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $installed = null,
        public readonly array $candidates = [],
        public readonly string $reason = '',
    ) {
    }

    public function id(): string
    {
        return $this->adoption->id();
    }

    public function requirement(): string
    {
        return sprintf('%s >= %s', $this->adoption->package(), $this->adoption->minVersion());
    }

    /** @return list<string> the files holding candidates, relative to the project */
    public function files(): array
    {
        return array_values(array_unique(array_map(static fn (Candidate $c) => $c->file, $this->candidates)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'title' => $this->title,
            'summary' => $this->summary,
            'package' => $this->adoption->package(),
            'min_version' => $this->adoption->minVersion(),
            'installed' => $this->installed,
            'docs' => $this->adoption->docs(),
            'rewrite' => $this->adoption->rector() ?? 'manual',
            'experimental' => $this->adoption->experimental(),
            'decision' => $this->decision,
            'reason' => $this->reason,
            'candidates' => array_map(static fn (Candidate $c) => $c->toArray(), $this->candidates),
        ];
    }
}
