<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Semver;

use Acencyril\UpliftBundle\Level;

/**
 * A stable release number, reduced to major.minor.patch ("v7.4.3", "7.4.3.0" and
 * "7.4.3" are the same version). Pre-releases and branches are not versions here:
 * Uplift only ever moves to a stable release.
 */
final class Version implements \Stringable
{
    private function __construct(public readonly int $major, public readonly int $minor, public readonly int $patch, public readonly int $segments)
    {
    }

    public static function parse(string $raw): ?self
    {
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?$/i', trim($raw), $m)) {
            return null;
        }

        return new self((int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), 1 + (int) (($m[2] ?? '') !== '') + (int) (($m[3] ?? '') !== ''));
    }

    public static function of(string $raw): self
    {
        return self::parse($raw) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a stable version.', $raw));
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /** "8.3" for 8.3.12. */
    public function branch(): string
    {
        return $this->major.'.'.$this->minor;
    }

    /** The size of the move from $this to $target (null when it is not a move up). */
    public function levelTo(self $target): ?Level
    {
        return match (true) {
            !$target->greaterThan($this) => null,
            $target->major !== $this->major => Level::Major,
            $target->minor !== $this->minor => Level::Minor,
            default => Level::Patch,
        };
    }

    /** Written with $segments numbers: 8.4.14 as "8", "8.4" or "8.4.14". */
    public function format(int $segments = 3): string
    {
        return implode('.', \array_slice([$this->major, $this->minor, $this->patch], 0, max(1, min(3, $segments))));
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
