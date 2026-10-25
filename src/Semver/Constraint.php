<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Semver;

/**
 * A Composer version constraint, enough of it to decide whether a version is allowed:
 * `^`, `~`, wildcards (`7.4.*`), comparisons, exact versions, hyphen ranges, `,` or
 * space for "and", `||` for "or". Stability flags (`@dev`) are ignored. Anything else
 * (`dev-main`, `self.version`) does not parse, and Uplift leaves that package alone.
 */
final class Constraint implements \Stringable
{
    /** @param list<list<array{string, Version}>> $alternatives */
    private function __construct(private readonly string $raw, private readonly array $alternatives)
    {
    }

    public static function parse(string $raw): ?self
    {
        $alternatives = [];
        foreach (preg_split('/\s*\|\|?\s*/', trim($raw)) ?: [] as $alternative) {
            $parsed = self::parseAnd($alternative);
            if (null === $parsed) {
                return null;
            }
            $alternatives[] = $parsed;
        }

        return [] === $alternatives ? null : new self(trim($raw), $alternatives);
    }

    public function accepts(Version $version): bool
    {
        foreach ($this->alternatives as $and) {
            foreach ($and as [$op, $bound]) {
                $c = $version->compare($bound);
                $ok = match ($op) {
                    '>=' => $c >= 0,
                    '>' => $c > 0,
                    '<' => $c < 0,
                    '<=' => $c <= 0,
                    '!=' => 0 !== $c,
                    default => 0 === $c,
                };
                if (!$ok) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** The lowest version allowed ("8.3.0" for ">=8.3", "^8.3 || ^9.0"). */
    public function lowerBound(): Version
    {
        $lowest = null;
        foreach ($this->alternatives as $and) {
            $floor = Version::of('0.0.0');
            foreach ($and as [$op, $bound]) {
                if (\in_array($op, ['>=', '>', '=='], true) && $bound->greaterThan($floor)) {
                    $floor = $bound;
                }
            }
            if (null === $lowest || $lowest->greaterThan($floor)) {
                $lowest = $floor;
            }
        }

        return $lowest ?? Version::of('0.0.0');
    }

    public function isOr(): bool
    {
        return \count($this->alternatives) > 1;
    }

    public function __toString(): string
    {
        return $this->raw;
    }

    /** @return list<array{string, Version}>|null */
    private static function parseAnd(string $and): ?array
    {
        $and = trim($and);
        if ('' === $and) {
            return null;
        }
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $and, $m)) {
            $from = Version::parse($m[1]);
            $to = Version::parse($m[2]);
            if (null === $from || null === $to) {
                return null;
            }
            // a partial upper bound is a wildcard: "1.0 - 2.1" allows every 2.1.x
            $upper = match ($to->segments) {
                1 => ['<', Version::of(($to->major + 1).'.0.0')],
                2 => ['<', Version::of($to->major.'.'.($to->minor + 1).'.0')],
                default => ['<=', $to],
            };

            return [['>=', $from], $upper];
        }

        $terms = [];
        $and = preg_replace('/(>=|<=|>|<|!=|==|=)\s+/', '$1', $and) ?? $and;
        foreach (preg_split('/\s*,\s*|\s+/', $and) ?: [] as $token) {
            $parsed = self::parseTerm($token);
            if (null === $parsed) {
                return null;
            }
            array_push($terms, ...$parsed);
        }

        return $terms;
    }

    /** @return list<array{string, Version}>|null */
    private static function parseTerm(string $token): ?array
    {
        $token = preg_replace('/@[a-z]+$/i', '', trim($token)) ?? '';
        if ('' === $token) {
            return [];
        }
        if (\in_array($token, ['*', 'x', 'X'], true)) {
            return [];
        }
        if (preg_match('/^v?(\d+)(?:\.(\d+))?\.[*xX]$/', $token, $m)) {
            if (!isset($m[2]) || '' === $m[2]) {
                return [['>=', Version::of($m[1].'.0.0')], ['<', Version::of(($m[1] + 1).'.0.0')]];
            }

            return [['>=', Version::of($m[1].'.'.$m[2].'.0')], ['<', Version::of($m[1].'.'.($m[2] + 1).'.0')]];
        }
        if (str_starts_with($token, '^')) {
            $v = Version::parse(substr($token, 1));
            if (null === $v) {
                return null;
            }
            $upper = match (true) {
                $v->major > 0 || 1 === $v->segments => ($v->major + 1).'.0.0',
                $v->minor > 0 || 2 === $v->segments => '0.'.($v->minor + 1).'.0',
                default => '0.0.'.($v->patch + 1),
            };

            return [['>=', $v], ['<', Version::of($upper)]];
        }
        if (str_starts_with($token, '~')) {
            $v = Version::parse(substr($token, 1));
            if (null === $v) {
                return null;
            }
            $upper = $v->segments <= 2 ? ($v->major + 1).'.0.0' : $v->major.'.'.($v->minor + 1).'.0';

            return [['>=', $v], ['<', Version::of($upper)]];
        }
        if (preg_match('/^(>=|<=|>|<|!=|==|=)?\s*(.+)$/', $token, $m)) {
            $v = Version::parse($m[2]);
            if (null === $v) {
                return null;
            }
            $op = '' === $m[1] || '=' === $m[1] ? '==' : $m[1];

            return [[$op, $v]];
        }

        return null;
    }
}
