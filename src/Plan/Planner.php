<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Plan;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Composer\ConstraintRewriter;
use Acencyril\UpliftBundle\Level;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Semver\Constraint;
use Acencyril\UpliftBundle\Semver\Version;
use Acencyril\UpliftBundle\Source\OutdatedReport;
use Acencyril\UpliftBundle\Source\PhpBranch;
use Acencyril\UpliftBundle\Source\PhpReleaseSource;
use Acencyril\UpliftBundle\Source\Release;
use Acencyril\UpliftBundle\Source\ReleaseSource;

/**
 * Lists what can move and decides each move against the policy.
 *
 * PHP is decided first: the packages are then checked against the PHP the project
 * will be on. A release that needs a PHP the project cannot reach (above
 * UPLIFT_PHP_MAX, or above the PHP it is on) is blocked, with the reason.
 *
 * One step at a time, the way upgrade guides go: each run looks at the next step only.
 *  - A package (Symfony included) first goes to the latest minor of its major (6.2 -> 6.4,
 *    the line where every deprecation of 7.0 shows); only from there does it move to the
 *    latest release of the next major (6.4 -> 7.x, never 8.x). Below 1.0 each minor is
 *    its own line (0.3 -> 0.4 -> 0.5).
 *  - PHP moves one branch at a time (8.1 -> 8.2 -> 8.3).
 * Within that step, at most three moves come out: the largest one the policy allows
 * ("apply"), a larger one that works but goes past the policy ("propose"), and a still
 * larger one that cannot be done ("blocked"). The steps after it are listed on each move
 * ("later"), for the next runs.
 */
final class Planner
{
    private const SETTING = ['php' => 'UPLIFT_PHP', 'symfony' => 'UPLIFT_SYMFONY', 'own' => 'UPLIFT_OWN_VENDOR', 'other' => 'UPLIFT_PACKAGES'];

    public function __construct(private readonly ReleaseSource $packages, private readonly PhpReleaseSource $php)
    {
    }

    public function plan(ComposerProject $project, Policy $policy, ?OutdatedReport $outdated = null, string $runtimePhp = \PHP_VERSION): Plan
    {
        $outdated ??= new OutdatedReport();
        $notes = [];
        $moves = [];

        // PHP
        $current = $project->phpVersion($runtimePhp);
        $branches = [];
        if (null === $current) {
            $notes[] = 'No PHP version found (config.platform.php, require.php or the runtime): PHP is left alone.';
        } else {
            try {
                $branches = $this->php->branches(array_values(array_unique(array_filter([$current->branch(), $policy->phpMax]))));
            } catch (\Throwable $e) {
                $notes[] = 'php.net could not be reached, PHP is left alone: '.$e->getMessage();
            }
        }
        $phpAfter = $current;
        if (null !== $current && [] !== $branches) {
            foreach ($branches as $b) {
                if ($b->branch() === $current->branch() && !$b->supported) {
                    $notes[] = sprintf('PHP %s is no longer supported by php.net (latest release %s).', $b->branch(), $b->latest);
                }
            }
            if (null !== $policy->phpMax && self::aboveCeiling($current, $policy->phpMax)) {
                $notes[] = sprintf('The project is on PHP %s, already above UPLIFT_PHP_MAX=%s.', $current, $policy->phpMax);
            }
            $candidates = array_map(static fn (PhpBranch $b) => new Release($b->latest), $branches);
            $problem = static fn (Release $r): ?string => null !== $policy->phpMax && self::aboveCeiling($r->version, $policy->phpMax)
                ? sprintf('above UPLIFT_PHP_MAX=%s', $policy->phpMax) : null;
            foreach (self::choose($current, $candidates, $policy->php, 'php', $problem, true) as [$release, $level, $decision, $reason, $later]) {
                $later = array_values(array_filter($later, static fn (string $v) => null === $policy->phpMax || !self::aboveCeiling(Version::of($v), $policy->phpMax)));
                $moves[] = new Move('php', 'php', $current, $release->version, $level, $decision, $reason, $project->requirePhp(), null, [], null, $later);
                if ('apply' === $decision) {
                    $phpAfter = $release->version;
                }
            }
        }
        $proposedPhp = array_values(array_filter($moves, static fn (Move $m) => 'propose' === $m->decision));
        $knownPhp = array_map(static fn (PhpBranch $b) => $b->latest, $branches);

        $phpProblem = static function (?string $requires) use ($phpAfter, $knownPhp, $policy, $proposedPhp): ?string {
            $c = null !== $requires ? Constraint::parse($requires) : null;
            if (null === $c || null === $phpAfter || $c->accepts($phpAfter)) {
                return null;
            }
            $reachable = array_filter($knownPhp, static fn (Version $v) => $c->accepts($v) && (null === $policy->phpMax || !self::aboveCeiling($v, $policy->phpMax)));
            if (null !== $policy->phpMax && [] === $reachable) {
                return sprintf('requires PHP %s, above UPLIFT_PHP_MAX=%s', $requires, $policy->phpMax);
            }
            $why = sprintf('requires PHP %s, the project is on %s', $requires, $phpAfter);
            foreach ($proposedPhp as $m) {
                if ($c->accepts($m->to)) {
                    return $why.sprintf(' (the proposed PHP move to %s would allow it)', $m->to->branch());
                }
            }

            return $why;
        };

        // Symfony: the symfony/* packages held to the framework's "X.Y.*" move together
        $requirements = $project->requirements();
        $group = self::symfonyGroup($project, $requirements);
        if (null !== $group) {
            [$constraint, $members] = $group;
            $lead = \in_array('symfony/framework-bundle', $members, true) ? 'symfony/framework-bundle' : $members[0];
            $installed = $project->installed($lead);
            if (null === $installed) {
                $notes[] = sprintf('%s is not in composer.lock: Symfony is left alone.', $lead);
            } else {
                $candidates = $this->releases($lead, $outdated, $notes);
                $problem = static fn (Release $r): ?string => $phpProblem($r->php)
                    ?? (null === ConstraintRewriter::rewrite($constraint, $r->version) ? sprintf('the constraint "%s" cannot be widened automatically', $constraint) : null);
                foreach (self::choose($installed, $candidates, $policy->symfony, 'symfony', $problem) as [$release, $level, $decision, $reason, $later]) {
                    $to = ConstraintRewriter::rewrite($constraint, $release->version);
                    $moves[] = new Move('symfony/*', 'symfony', $installed, $release->version, $level, $decision, $reason, $constraint, $to, $members, $release->php, $later);
                }
            }
            foreach ($members as $m) {
                unset($requirements[$m]);
            }
        }

        // every other direct dependency
        foreach ($requirements as $name => ['constraint' => $constraint]) {
            if (null === Constraint::parse($constraint)) {
                continue; // dev-main, self.version…: not ours to move
            }
            $installed = $project->installed($name);
            if (null === $installed) {
                if ($project->hasLock()) {
                    $notes[] = sprintf('%s is not installed at a stable version (composer.lock): skipped.', $name);
                }
                continue;
            }
            $abandoned = $outdated->abandoned($name);
            if (false !== $abandoned) {
                $notes[] = sprintf('%s is abandoned%s.', $name, \is_string($abandoned) && '' !== $abandoned ? ', use '.$abandoned.' instead' : '');
            }
            $category = $policy->isOwn($name) ? 'own' : 'other';
            $candidates = $this->releases($name, $outdated, $notes);
            $problem = static fn (Release $r): ?string => $phpProblem($r->php)
                ?? (null === ConstraintRewriter::rewrite($constraint, $r->version) ? sprintf('the constraint "%s" cannot be widened automatically', $constraint) : null);
            foreach (self::choose($installed, $candidates, $policy->levelFor($category), $category, $problem) as [$release, $level, $decision, $reason, $later]) {
                $moves[] = new Move($name, $category, $installed, $release->version, $level, $decision, $reason, $constraint, ConstraintRewriter::rewrite($constraint, $release->version), [], $release->php, $later);
            }
        }

        if (!$policy->enabled) {
            $notes[] = 'UPLIFT_ENABLED is not 1: uplift:apply changes nothing.';
        }

        return new Plan($policy, $moves, $notes, $current, $phpAfter);
    }

    /**
     * @param array<string, array{constraint: string, dev: bool}> $requirements
     *
     * @return array{string, list<string>}|null the shared constraint and the packages held to it
     */
    public static function symfonyGroup(ComposerProject $project, array $requirements): ?array
    {
        $constraint = $requirements['symfony/framework-bundle']['constraint'] ?? $project->symfonyRequire();
        if (null === $constraint || !preg_match('/^\d+\.\d+\.\*$/', $constraint)) {
            $constraint = $project->symfonyRequire();
        }
        if (null === $constraint || !preg_match('/^\d+\.\d+\.\*$/', $constraint)) {
            return null;
        }
        $members = [];
        foreach ($requirements as $name => $r) {
            if (str_starts_with($name, 'symfony/') && $r['constraint'] === $constraint) {
                $members[] = $name;
            }
        }

        return [] === $members ? null : [$constraint, $members];
    }

    /**
     * @param list<string> $notes
     *
     * @return list<Release>
     */
    private function releases(string $name, OutdatedReport $outdated, array &$notes): array
    {
        $byVersion = [];
        try {
            foreach ($this->packages->releases($name) as $r) {
                $byVersion[(string) $r->version] = $r;
            }
        } catch (\Throwable $e) {
            $notes[] = sprintf('The releases of %s could not be read (%s): only composer outdated is used.', $name, $e->getMessage());
        }
        $latest = $outdated->latest($name);
        if (null !== $latest && !isset($byVersion[(string) $latest])) {
            $byVersion[(string) $latest] = new Release($latest);
        }

        return array_values($byVersion);
    }

    /**
     * The steps from $from to the newest of $versions, one per run: the latest release of
     * the current major when the project is not on its latest minor yet, then the latest
     * release of each next major. With $perMinor (PHP), and below 1.0, a line is a
     * major.minor branch instead of a major.
     *
     * @param list<Version> $versions
     *
     * @return list<Version>
     */
    public static function ladder(Version $from, array $versions, bool $perMinor = false): array
    {
        $line = static fn (Version $v): string => $perMinor || 0 === $v->major ? $v->branch() : (string) $v->major;
        $byLine = [];
        foreach ($versions as $v) {
            $byLine[$line($v)][] = $v;
        }
        $latest = static function (array $list): Version {
            usort($list, static fn (Version $a, Version $b) => $b->compare($a));

            return $list[0];
        };
        $steps = [];
        $pos = $from;
        while (true) {
            $here = array_values(array_filter($byLine[$line($pos)] ?? [], static fn (Version $v) => $v->greaterThan($pos)));
            if ([] !== $here && $latest($here)->minor > $pos->minor) {
                $next = $latest($here); // not on the latest minor of its major yet
            } else {
                $ahead = array_values(array_filter($versions, static fn (Version $v) => $v->greaterThan($pos) && $line($v) !== $line($pos)));
                if ([] !== $ahead) {
                    usort($ahead, static fn (Version $a, Version $b) => $a->compare($b));
                    $next = $latest($byLine[$line($ahead[0])]);
                } elseif ([] !== $here) {
                    $next = $latest($here);
                } else {
                    break;
                }
            }
            $steps[] = $next;
            $pos = $next;
        }

        return $steps;
    }

    /**
     * @param list<Release>              $candidates
     * @param callable(Release): ?string $problem
     *
     * @return list<array{Release, Level, string, string, list<string>}>
     */
    private static function choose(Version $from, array $candidates, Level $allowed, string $category, callable $problem, bool $perMinor = false): array
    {
        $ladder = self::ladder($from, array_map(static fn (Release $r) => $r->version, $candidates), $perMinor);
        if ([] === $ladder) {
            return [];
        }
        // this run looks at the next step only
        $candidates = array_filter($candidates, static fn (Release $r) => !$r->version->greaterThan($ladder[0]));
        usort($candidates, static fn (Release $a, Release $b) => $b->version->compare($a->version));
        $apply = $propose = $blocked = null;
        foreach ($candidates as $c) {
            $level = $from->levelTo($c->version);
            if (null === $level) {
                continue;
            }
            $why = $problem($c);
            if (null !== $why) {
                $blocked ??= [$c, $level, 'blocked', $why];
            } elseif ($allowed->allows($level)) {
                $apply ??= [$c, $level, 'apply', ''];
            } else {
                $propose ??= [$c, $level, 'propose', self::beyond($category, $allowed, $level)];
            }
        }
        $out = [];
        $best = null;
        foreach ([$apply, $propose, $blocked] as $m) {
            if (null !== $m && (null === $best || $m[0]->version->greaterThan($best))) {
                $to = $m[0]->version;
                $m[] = array_values(array_map('strval', array_filter($ladder, static fn (Version $v) => $v->greaterThan($to))));
                $out[] = $m;
                $best = $m[0]->version;
            }
        }

        return $out;
    }

    private static function beyond(string $category, Level $allowed, Level $level): string
    {
        if ('own' === $category) {
            return sprintf('own packages move on their own up to a %s; a %s waits for a decision', $allowed->value, $level->value);
        }

        return Level::Off === $allowed
            ? sprintf('%s=off: every move waits for a decision', self::SETTING[$category])
            : sprintf('%s=%s: a %s move waits for a decision', self::SETTING[$category], $allowed->value, $level->value);
    }

    private static function aboveCeiling(Version $v, string $ceiling): bool
    {
        return $v->compare(Version::of($ceiling.'.0')) > 0 && $v->branch() !== $ceiling;
    }
}
