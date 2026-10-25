<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Composer;

/**
 * Replaces one string value in a JSON text and leaves every other byte alone: the
 * diff of composer.json shows the constraints that moved, nothing else (no reordering,
 * no re-indenting, no "{}" turned into "[]").
 */
final class JsonEditor
{
    /**
     * @param list<string> $path e.g. ['require', 'symfony/console'] or ['config', 'platform', 'php']
     *
     * @return string|null the new text, or null when the path does not lead to a string
     */
    public static function replace(string $json, array $path, string $value): ?string
    {
        $span = self::find($json, $path);
        if (null === $span || '"' !== $json[$span[0]]) {
            return null;
        }

        return substr($json, 0, $span[0]).json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).substr($json, $span[1]);
    }

    /**
     * Where the value at $path starts and ends (end excluded).
     *
     * @param list<string> $path
     *
     * @return array{int, int}|null
     */
    public static function find(string $json, array $path): ?array
    {
        $pos = self::skipSpace($json, 0);
        $end = self::valueEnd($json, $pos);
        if (null === $end) {
            return null;
        }
        $span = [$pos, $end];
        foreach ($path as $key) {
            if ('{' !== ($json[$span[0]] ?? '')) {
                return null;
            }
            $span = self::member($json, $span[0], $key);
            if (null === $span) {
                return null;
            }
        }

        return $span;
    }

    /** @return array{int, int}|null the value of $key in the object opening at $open */
    private static function member(string $json, int $open, string $key): ?array
    {
        $pos = self::skipSpace($json, $open + 1);
        while ($pos < \strlen($json) && '}' !== $json[$pos]) {
            $keyEnd = self::stringEnd($json, $pos);
            if (null === $keyEnd) {
                return null;
            }
            $name = json_decode(substr($json, $pos, $keyEnd - $pos));
            $pos = self::skipSpace($json, $keyEnd);
            if (':' !== ($json[$pos] ?? '')) {
                return null;
            }
            $start = self::skipSpace($json, $pos + 1);
            $end = self::valueEnd($json, $start);
            if (null === $end) {
                return null;
            }
            if ($name === $key) {
                return [$start, $end];
            }
            $pos = self::skipSpace($json, $end);
            if (',' === ($json[$pos] ?? '')) {
                $pos = self::skipSpace($json, $pos + 1);
            }
        }

        return null;
    }

    private static function valueEnd(string $json, int $pos): ?int
    {
        $c = $json[$pos] ?? '';
        if ('"' === $c) {
            return self::stringEnd($json, $pos);
        }
        if ('{' === $c || '[' === $c) {
            $depth = 0;
            for ($i = $pos, $n = \strlen($json); $i < $n; ++$i) {
                $ch = $json[$i];
                if ('"' === $ch) {
                    $i = (self::stringEnd($json, $i) ?? $n) - 1;
                } elseif ('{' === $ch || '[' === $ch) {
                    ++$depth;
                } elseif ('}' === $ch || ']' === $ch) {
                    if (0 === --$depth) {
                        return $i + 1;
                    }
                }
            }

            return null;
        }
        if (preg_match('/\G(?:true|false|null|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $json, $m, 0, $pos)) {
            return $pos + \strlen($m[0]);
        }

        return null;
    }

    private static function stringEnd(string $json, int $pos): ?int
    {
        if ('"' !== ($json[$pos] ?? '')) {
            return null;
        }
        for ($i = $pos + 1, $n = \strlen($json); $i < $n; ++$i) {
            if ('\\' === $json[$i]) {
                ++$i;
            } elseif ('"' === $json[$i]) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function skipSpace(string $json, int $pos): int
    {
        return $pos + strspn($json, " \t\r\n", $pos);
    }
}
