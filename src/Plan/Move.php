<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Plan;

use Acencyril\UpliftBundle\Level;
use Acencyril\UpliftBundle\Semver\Version;

/**
 * One possible move of PHP, of the Symfony packages together, or of one package.
 *
 * decision: "apply"   allowed by the policy, done by uplift:apply;
 *           "propose" works, but goes past the policy: a person decides;
 *           "blocked" cannot be done here, the reason says why.
 */
final class Move
{
    public const CATEGORIES = ['php', 'symfony', 'own', 'other'];

    /**
     * @param list<string> $members the packages moved together ("symfony/*")
     * @param list<string> $later   the next steps, one per later run (7.4 now; then 8.1)
     */
    public function __construct(
        public readonly string $package,
        public readonly string $category,
        public readonly Version $from,
        public readonly Version $to,
        public readonly Level $level,
        public readonly string $decision,
        public readonly string $reason = '',
        public readonly ?string $constraintFrom = null,
        public readonly ?string $constraintTo = null,
        public readonly array $members = [],
        public readonly ?string $requiresPhp = null,
        public readonly array $later = [],
    ) {
    }

    /** Whether composer.json has to change for this move. */
    public function rewrites(): bool
    {
        return null !== $this->constraintTo && $this->constraintTo !== $this->constraintFrom;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'category' => $this->category,
            'from' => (string) $this->from,
            'to' => (string) $this->to,
            'level' => $this->level->value,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'constraint_from' => $this->constraintFrom,
            'constraint_to' => $this->rewrites() ? $this->constraintTo : null,
            'members' => $this->members,
            'requires_php' => $this->requiresPhp,
            'later' => $this->later,
        ];
    }
}
