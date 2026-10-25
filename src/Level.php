<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle;

/**
 * The size of a move, by the number that changes: 7.4.3 -> 7.4.8 is a patch,
 * 0.3.2 -> 0.4.0 or 7.3 -> 7.4 a minor, 7.4 -> 8.0 a major. As a policy, the largest
 * move done automatically ("off": none).
 */
enum Level: string
{
    case Off = 'off';
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    public function rank(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Patch => 1,
            self::Minor => 2,
            self::Major => 3,
        };
    }

    public function allows(self $move): bool
    {
        return Level::Off !== $move && $move->rank() <= $this->rank();
    }

    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    public static function fromSetting(string $name, ?string $value, self $default): self
    {
        $value = strtolower(trim((string) $value));
        if ('' === $value) {
            return $default;
        }

        return self::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf('%s must be off, patch, minor or major, "%s" given.', $name, $value));
    }
}
