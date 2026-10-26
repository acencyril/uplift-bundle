<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Plan;

use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Semver\Version;

/** Everything that can move, decided against a policy. */
final class Plan
{
    /**
     * @param list<Move>   $moves
     * @param list<string> $notes things a person should know (an unsupported PHP branch, an abandoned package…)
     */
    public function __construct(
        public readonly Policy $policy,
        public readonly array $moves,
        public readonly array $notes,
        public readonly ?Version $php,
        public readonly ?Version $phpAfter,
    ) {
    }

    /** @return list<Move> */
    public function toApply(): array
    {
        return array_values(array_filter($this->moves, static fn (Move $m) => 'apply' === $m->decision));
    }

    /** @return list<Move> */
    public function withDecision(string $decision): array
    {
        return array_values(array_filter($this->moves, static fn (Move $m) => $decision === $m->decision));
    }

    /**
     * The way ahead for each package that is more than one run behind:
     * "7.3.5 -> 7.4.8 now; then 8.1.9 in later runs".
     *
     * @return array<string, string> package => path
     */
    public function paths(): array
    {
        $first = [];
        foreach ($this->moves as $m) {
            if (!isset($first[$m->package]) || 'apply' === $m->decision) {
                $first[$m->package] ??= $m;
                if ('apply' === $m->decision) {
                    $first[$m->package] = $m;
                }
            }
        }
        $out = [];
        foreach ($first as $package => $m) {
            if ([] === $m->later) {
                continue;
            }
            $now = match ($m->decision) {
                'apply' => 'now',
                'propose' => 'waits for a decision',
                default => 'blocked',
            };
            $out[$package] = sprintf('%s -> %s %s; then %s in later runs', $m->from, $m->to, $now, implode(', ', $m->later));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy' => $this->policy->toArray(),
            'php' => ['current' => $this->php?->format(), 'after' => $this->phpAfter?->format(), 'max' => $this->policy->phpMax],
            'moves' => array_map(static fn (Move $m) => $m->toArray(), $this->moves),
            'paths' => $this->paths(),
            'notes' => $this->notes,
        ];
    }
}
