<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Composer;

use Acencyril\UpliftBundle\Semver\Constraint;
use Acencyril\UpliftBundle\Semver\Version;

/**
 * The new constraint that lets a package reach a version past its current ceiling,
 * written the way the old one was: "7.4.*" -> "8.0.*", "^0.3" -> "^0.4",
 * "~2.13.1" -> "~3.0.2", "^6.4 || ^7.0" -> "^6.4 || ^7.0 || ^8.0". A constraint that
 * already allows the version is kept. Null when the form cannot be carried over
 * (">=1 <2" and the like): the move then waits for a person.
 */
final class ConstraintRewriter
{
    public static function rewrite(string $constraint, Version $target): ?string
    {
        $parsed = Constraint::parse($constraint);
        if (null === $parsed) {
            return null;
        }
        if ($parsed->accepts($target)) {
            return $constraint;
        }
        if ($parsed->isOr()) {
            $parts = preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [];
            $last = self::single((string) end($parts), $target);

            return null === $last ? null : trim($constraint).' || '.$last;
        }

        return self::single(trim($constraint), $target);
    }

    /**
     * The PHP requirement raised to a new branch, keeping its form: ">=8.3" -> ">=8.4",
     * "^8.3" -> "^8.4", "8.3.*" -> "8.4.*", "~8.3.0" -> "~8.4.0".
     */
    public static function raisePhp(string $constraint, Version $branch): ?string
    {
        $floor = Version::of($branch->branch().'.0');
        if (preg_match('/^(>=|\^|~)\s*v?(\d+(?:\.\d+){0,2})$/', trim($constraint), $m)) {
            return $m[1].$floor->format(max(2, Version::of($m[2])->segments));
        }
        if (preg_match('/^v?\d+\.\d+\.[*x]$/', trim($constraint))) {
            return $branch->branch().'.*';
        }

        return null;
    }

    private static function single(string $c, Version $target): ?string
    {
        $c = preg_replace('/@[a-z]+$/i', '', $c) ?? $c;
        if (preg_match('/^(\^|~)v?(\d+(?:\.\d+){0,2})$/', $c, $m)) {
            return $m[1].$target->format(Version::of($m[2])->segments);
        }
        if (preg_match('/^v?\d+\.\d+\.[*x]$/', $c)) {
            return $target->branch().'.*';
        }
        if (preg_match('/^v?\d+\.[*x]$/', $c)) {
            return $target->major.'.*';
        }
        if (preg_match('/^=?=?v?\d+(?:\.\d+){0,2}$/', $c)) {
            return $target->format();
        }

        return null;
    }
}
