<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Plan\Move;
use Acencyril\UpliftBundle\Plan\Plan;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Semver\Version;
use Acencyril\UpliftBundle\Source\OutdatedReport;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedPhpSource;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedReleaseSource;
use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
    /** @param array<string, string> $env */
    private static function plan(string $fixture, array $env = [], ?FixedReleaseSource $source = null, ?OutdatedReport $outdated = null, ?FixedPhpSource $php = null): Plan
    {
        return (new Planner($source ?? FixedReleaseSource::standard(), $php ?? new FixedPhpSource()))
            ->plan(new ComposerProject(__DIR__.'/Fixtures/'.$fixture), Policy::fromEnv($env + ['UPLIFT_ENABLED' => '1']), $outdated, '8.3.12');
    }

    /** @return array<string, string> "package decision" => "from -> to level" */
    private static function table(Plan $plan): array
    {
        $out = [];
        foreach ($plan->moves as $m) {
            $out[$m->package.' '.$m->decision] = $m->from.' -> '.$m->to.' '.$m->level->value;
        }

        return $out;
    }

    private static function move(Plan $plan, string $package, string $decision): Move
    {
        foreach ($plan->moves as $m) {
            if ($m->package === $package && $m->decision === $decision) {
                return $m;
            }
        }
        self::fail("no $decision move for $package");
    }

    /** @var list<string> */
    private static array $tmp = [];

    /**
     * A project on Symfony $symfony and PHP $php, with vendor/lib at $lib; releases as
     * an upgrade guide would see them (no PHP requirement, to look at the steps alone).
     *
     * @param array<string, string> $env
     */
    private static function steps(string $symfony, string $php = '8.1', string $lib = '2.3.0', array $env = []): Plan
    {
        $dir = sys_get_temp_dir().'/uplift-steps-'.bin2hex(random_bytes(4));
        mkdir($dir);
        self::$tmp[] = $dir;
        $line = Version::of($symfony)->branch().'.*';
        file_put_contents($dir.'/composer.json', json_encode([
            'name' => 'example/steps',
            'require' => ['php' => '>='.$php, 'symfony/framework-bundle' => $line, 'symfony/console' => $line, 'vendor/lib' => '^'.Version::of($lib)->branch()],
            'config' => ['platform' => ['php' => $php]],
            'extra' => ['symfony' => ['require' => $line]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/composer.lock', json_encode(['packages' => [
            ['name' => 'symfony/framework-bundle', 'version' => 'v'.$symfony],
            ['name' => 'symfony/console', 'version' => 'v'.$symfony],
            ['name' => 'vendor/lib', 'version' => $lib],
        ], 'packages-dev' => []]));
        $none = static fn (array $versions) => array_fill_keys($versions, null);
        $releases = new FixedReleaseSource([
            'symfony/framework-bundle' => $none(['6.2.14', '6.3.12', '6.4.10', '6.4.26', '7.0.10', '7.3.5', '7.4.1', '7.4.8', '8.0.5', '8.1.2']),
            'vendor/lib' => $none(['2.3.0', '2.3.4', '2.7.1', '3.0.0', '3.2.0', '4.0.0']),
        ]);

        return (new Planner($releases, new FixedPhpSource()))->plan(new ComposerProject($dir), Policy::fromEnv($env + ['UPLIFT_ENABLED' => '1', 'UPLIFT_PHP' => 'major', 'UPLIFT_SYMFONY' => 'major', 'UPLIFT_PACKAGES' => 'major']), null, '8.3.12');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tmp as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
        self::$tmp = [];
    }

    public function testSymfonyGoesThroughTheLastMinorOfItsMajorFirst(): void
    {
        $plan = self::steps('6.2.14');
        $sf = self::move($plan, 'symfony/*', 'apply');
        self::assertSame('6.4.26', (string) $sf->to, '6.4 first, where every deprecation of 7.0 shows');
        self::assertSame('minor', $sf->level->value);
        self::assertSame('6.4.*', $sf->constraintTo);
        self::assertSame(['7.4.8', '8.1.2'], $sf->later);
        self::assertSame([], array_filter($plan->moves, static fn (Move $m) => 'symfony/*' === $m->package && 6 !== $m->to->major), 'no 7.x or 8.x in this run');
        self::assertSame('6.2.14 -> 6.4.26 now; then 7.4.8, 8.1.2 in later runs', $plan->paths()['symfony/*']);
    }

    public function testFromTheLastMinorAMajorGoesToTheNextMajorOnly(): void
    {
        $sf = self::move(self::steps('6.4.10'), 'symfony/*', 'apply');
        self::assertSame('7.4.8', (string) $sf->to, 'the latest 7.x, never 8.x');
        self::assertSame('major', $sf->level->value);
        self::assertSame('7.4.*', $sf->constraintTo);
        self::assertSame(['8.1.2'], $sf->later);
    }

    public function testTheNextMajorIsTakenAtItsLatestRelease(): void
    {
        $sf = self::move(self::steps('7.4.1'), 'symfony/*', 'apply');
        self::assertSame('8.1.2', (string) $sf->to, '8.1 is a minor of the next major');
        self::assertSame('8.1.*', $sf->constraintTo);
        self::assertSame([], $sf->later);
    }

    public function testOneMinorBehindTheNextMajorWaits(): void
    {
        $plan = self::steps('7.3.5', '8.2', '2.3.0', ['UPLIFT_SYMFONY' => 'minor']);
        self::assertSame(['symfony/* apply' => '7.3.5 -> 7.4.8 minor'], array_filter(self::table($plan), static fn (string $k) => str_starts_with($k, 'symfony/*'), \ARRAY_FILTER_USE_KEY));
        self::assertSame(['8.1.2'], self::move($plan, 'symfony/*', 'apply')->later);
    }

    public function testPhpMovesOneBranchPerRun(): void
    {
        $plan = self::steps('6.4.10');
        $php = self::move($plan, 'php', 'apply');
        self::assertSame('8.2.29', (string) $php->to, '8.1 -> 8.2, never 8.4, even with UPLIFT_PHP=major');
        self::assertSame(['8.3.27', '8.4.14'], $php->later);
        self::assertSame('8.2.29', (string) $plan->phpAfter);

        $capped = self::move(self::steps('6.4.10', '8.1', '2.3.0', ['UPLIFT_PHP_MAX' => '8.2']), 'php', 'apply');
        self::assertSame('8.2.29', (string) $capped->to);
        self::assertSame([], $capped->later, 'the ceiling still applies');
    }

    public function testAPackageGoesToTheLatestMinorOfItsMajorBeforeTheNextMajor(): void
    {
        $plan = self::steps('6.4.10');
        $lib = self::move($plan, 'vendor/lib', 'apply');
        self::assertSame('2.7.1', (string) $lib->to, '2.3 -> 2.x latest first, not 4.0');
        self::assertSame(['3.2.0', '4.0.0'], $lib->later);
        self::assertSame([], array_filter($plan->moves, static fn (Move $m) => 'vendor/lib' === $m->package && 2 !== $m->to->major));

        self::assertSame('3.2.0', (string) self::move(self::steps('6.4.10', '8.1', '2.7.1'), 'vendor/lib', 'apply')->to);
    }

    public function testTheLadder(): void
    {
        $v = static fn (string ...$l) => array_map(Version::of(...), $l);
        $s = static fn (array $l) => array_map('strval', $l);
        self::assertSame(['0.4.0', '1.0.0'], $s(Planner::ladder(Version::of('0.3.1'), $v('0.3.4', '0.4.0', '1.0.0'))), 'below 1.0 a minor is a line');
        self::assertSame(['7.4.8'], $s(Planner::ladder(Version::of('7.4.1'), $v('7.4.8'))));
        self::assertSame(['8.2.29', '8.3.27', '8.4.14'], $s(Planner::ladder(Version::of('8.1.0'), $v('8.1.33', '8.2.29', '8.3.27', '8.4.14'), true)));
        self::assertSame([], Planner::ladder(Version::of('8.0.0'), $v('7.4.8')));
    }

    public function testWithTheSafeDefaultsOnlyPatchesAreApplied(): void
    {
        $plan = self::plan('site');

        self::assertSame([
            'php apply' => '8.3.12 -> 8.3.27 patch',
            'php propose' => '8.3.12 -> 8.4.14 minor',
            'symfony/* apply' => '7.4.1 -> 7.4.8 patch',
            'symfony/* blocked' => '7.4.1 -> 8.0.5 major',
            'acencyril/sentinelle-bundle apply' => '0.3.1 -> 0.4.0 minor',
            'doctrine/orm apply' => '3.3.1 -> 3.3.4 patch',
            'doctrine/orm propose' => '3.3.1 -> 3.5.2 minor',
            'symfony/flex propose' => '2.8.0 -> 2.9.0 minor',
            'twig/twig apply' => '3.21.0 -> 3.21.1 patch',
            'phpunit/phpunit apply' => '11.5.2 -> 11.5.40 patch',
            'phpunit/phpunit propose' => '11.5.2 -> 12.4.0 major',
        ], self::table($plan));
        self::assertSame('8.3.27', (string) $plan->phpAfter);
        self::assertSame(['1.0.0'], self::move($plan, 'acencyril/sentinelle-bundle', 'apply')->later, '1.0 comes on a later run');
        self::assertSame(['3.5.2', '4.0.0'], self::move($plan, 'doctrine/orm', 'apply')->later);
        self::assertSame('3.3.1 -> 3.3.4 now; then 3.5.2, 4.0.0 in later runs', $plan->paths()['doctrine/orm']);
    }

    public function testEveryMoveIsClassified(): void
    {
        $plan = self::plan('site');
        $categories = [];
        foreach ($plan->moves as $m) {
            $categories[$m->package] = $m->category;
        }
        self::assertSame(['php' => 'php', 'symfony/*' => 'symfony', 'acencyril/sentinelle-bundle' => 'own', 'doctrine/orm' => 'other', 'symfony/flex' => 'other', 'twig/twig' => 'other', 'phpunit/phpunit' => 'other'], $categories);
    }

    public function testSymfonyMovesAsOneLineWithItsConstraint(): void
    {
        $plan = self::plan('site', ['UPLIFT_SYMFONY' => 'major', 'UPLIFT_PHP' => 'minor']);
        $sf = self::move($plan, 'symfony/*', 'apply');
        self::assertSame('8.0.5', (string) $sf->to);
        self::assertSame('7.4.*', $sf->constraintFrom);
        self::assertSame('8.0.*', $sf->constraintTo);
        self::assertSame(['symfony/console', 'symfony/framework-bundle', 'symfony/yaml', 'symfony/browser-kit'], $sf->members, 'symfony/flex has its own versions');
        self::assertSame('8.4.14', (string) $plan->phpAfter, 'PHP moved first, so Symfony 8 fits');
    }

    public function testAPackageThatNeedsANewerPhpIsBlockedWithTheReason(): void
    {
        $plan = self::plan('site');
        self::assertSame('requires PHP >=8.4, the project is on 8.3.27 (the proposed PHP move to 8.4 would allow it)', self::move($plan, 'symfony/*', 'blocked')->reason);
    }

    public function testMovesPastAConstraintRewriteIt(): void
    {
        $plan = self::plan('site');
        $own = self::move($plan, 'acencyril/sentinelle-bundle', 'apply');
        self::assertTrue($own->rewrites());
        self::assertSame('^0.4', $own->constraintTo);
        self::assertFalse(self::move($plan, 'doctrine/orm', 'propose')->rewrites(), '3.5 is inside ^3.3');
        self::assertSame('^12.4', self::move($plan, 'phpunit/phpunit', 'propose')->constraintTo);
        self::assertSame('^0.4', self::move($plan, 'acencyril/sentinelle-bundle', 'apply')->constraintTo);
        self::assertStringContainsString('UPLIFT_PACKAGES=patch: a minor move waits for a decision', self::move($plan, 'doctrine/orm', 'propose')->reason);
        $plan = self::plan('site', [], new FixedReleaseSource(['acencyril/sentinelle-bundle' => ['0.3.1' => '>=8.1', '1.0.0' => '>=8.1']]));
        self::assertStringContainsString('own packages move on their own up to a minor', self::move($plan, 'acencyril/sentinelle-bundle', 'propose')->reason);
    }

    public function testTheCeilingKeepsAnOldServerWhereItIs(): void
    {
        $plan = self::plan('legacy', ['UPLIFT_PHP' => 'major', 'UPLIFT_SYMFONY' => 'major', 'UPLIFT_PACKAGES' => 'major', 'UPLIFT_PHP_MAX' => '8.1']);

        self::assertSame([
            'php apply' => '8.1.0 -> 8.1.33 patch',
            'php blocked' => '8.1.0 -> 8.2.29 minor',
            'symfony/* apply' => '6.4.10 -> 6.4.26 patch',
            'symfony/* blocked' => '6.4.10 -> 7.4.8 major',
            'monolog/monolog apply' => '2.9.1 -> 2.10.0 minor',
            'acencyril/fineprint-bundle apply' => '0.1.0 -> 0.2.0 minor',
        ], self::table($plan));
        self::assertSame('above UPLIFT_PHP_MAX=8.1', self::move($plan, 'php', 'blocked')->reason);
        self::assertSame([], self::move($plan, 'php', 'apply')->later, 'no step above the ceiling is listed');
        self::assertSame('requires PHP >=8.2, above UPLIFT_PHP_MAX=8.1', self::move($plan, 'symfony/*', 'blocked')->reason);
        self::assertSame(['3.9.0'], self::move($plan, 'monolog/monolog', 'apply')->later);
        self::assertContains('PHP 8.1 is no longer supported by php.net (latest release 8.1.33).', $plan->notes);
    }

    public function testOffMeansProposedOnly(): void
    {
        $plan = self::plan('site', ['UPLIFT_PHP' => 'off', 'UPLIFT_SYMFONY' => 'off', 'UPLIFT_PACKAGES' => 'off', 'UPLIFT_OWN_VENDOR' => 'none']);
        self::assertSame([], $plan->toApply());
        self::assertStringContainsString('UPLIFT_SYMFONY=off', self::move($plan, 'symfony/*', 'propose')->reason);
        self::assertSame('other', self::move($plan, 'acencyril/sentinelle-bundle', 'propose')->category);
    }

    public function testComposerOutdatedFillsInWhenPackagistCannotBeReached(): void
    {
        $outdated = OutdatedReport::fromJson((string) json_encode(['installed' => [
            ['name' => 'twig/twig', 'version' => 'v3.21.0', 'latest' => 'v3.21.1', 'latest-status' => 'semver-safe-update'],
            ['name' => 'doctrine/orm', 'version' => '3.3.1', 'latest' => '3.5.2', 'latest-status' => 'semver-safe-update', 'abandoned' => 'doctrine/orm-next'],
        ]]));
        $plan = self::plan('site', [], new FixedReleaseSource([], true), $outdated);

        self::assertSame('3.21.1', (string) self::move($plan, 'twig/twig', 'apply')->to);
        self::assertSame('3.5.2', (string) self::move($plan, 'doctrine/orm', 'propose')->to);
        self::assertContains('doctrine/orm is abandoned, use doctrine/orm-next instead.', $plan->notes);
        self::assertNotEmpty(array_filter($plan->notes, static fn (string $n) => str_contains($n, 'could not be read (network down)')));
    }

    public function testWithoutPhpNetPhpIsLeftAlone(): void
    {
        $plan = self::plan('site', [], null, null, new FixedPhpSource([], true));
        self::assertSame([], array_filter($plan->moves, static fn (Move $m) => 'php' === $m->package));
        self::assertNotEmpty(array_filter($plan->notes, static fn (string $n) => str_starts_with($n, 'php.net could not be reached')));
        self::assertSame('8.3.12', (string) $plan->phpAfter);
    }

    public function testDisabledStillPlans(): void
    {
        $plan = (new Planner(FixedReleaseSource::standard(), new FixedPhpSource()))->plan(new ComposerProject(__DIR__.'/Fixtures/site'), Policy::fromEnv([]), null, '8.3.12');
        self::assertNotEmpty($plan->toApply());
        self::assertContains('UPLIFT_ENABLED is not 1: uplift:apply changes nothing.', $plan->notes);
    }
}
