<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Plan\Move;
use Acencyril\UpliftBundle\Plan\Plan;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Policy;
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

    public function testWithTheSafeDefaultsOnlyPatchesAreApplied(): void
    {
        $plan = self::plan('site');

        self::assertSame([
            'php apply' => '8.3.12 -> 8.3.27 patch',
            'php propose' => '8.3.12 -> 8.4.14 minor',
            'symfony/* apply' => '7.4.1 -> 7.4.8 patch',
            'symfony/* blocked' => '7.4.1 -> 8.0.5 major',
            'acencyril/sentinelle-bundle apply' => '0.3.1 -> 0.4.0 minor',
            'acencyril/sentinelle-bundle propose' => '0.3.1 -> 1.0.0 major',
            'doctrine/orm apply' => '3.3.1 -> 3.3.4 patch',
            'doctrine/orm propose' => '3.3.1 -> 3.5.2 minor',
            'doctrine/orm blocked' => '3.3.1 -> 4.0.0 major',
            'symfony/flex propose' => '2.8.0 -> 2.9.0 minor',
            'twig/twig apply' => '3.21.0 -> 3.21.1 patch',
            'phpunit/phpunit apply' => '11.5.2 -> 11.5.40 patch',
            'phpunit/phpunit propose' => '11.5.2 -> 12.4.0 major',
        ], self::table($plan));
        self::assertSame('8.3.27', (string) $plan->phpAfter);
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
        self::assertSame('requires PHP ^8.4, the project is on 8.3.27 (the proposed PHP move to 8.4 would allow it)', self::move($plan, 'doctrine/orm', 'blocked')->reason);
    }

    public function testMovesPastAConstraintRewriteIt(): void
    {
        $plan = self::plan('site');
        $own = self::move($plan, 'acencyril/sentinelle-bundle', 'apply');
        self::assertTrue($own->rewrites());
        self::assertSame('^0.4', $own->constraintTo);
        self::assertFalse(self::move($plan, 'doctrine/orm', 'propose')->rewrites(), '3.5 is inside ^3.3');
        self::assertSame('^12.4', self::move($plan, 'phpunit/phpunit', 'propose')->constraintTo);
        self::assertStringContainsString('UPLIFT_PACKAGES=patch: a minor move waits for a decision', self::move($plan, 'doctrine/orm', 'propose')->reason);
        self::assertStringContainsString('own packages move on their own up to a minor', self::move($plan, 'acencyril/sentinelle-bundle', 'propose')->reason);
    }

    public function testTheCeilingKeepsAnOldServerWhereItIs(): void
    {
        $plan = self::plan('legacy', ['UPLIFT_PHP' => 'major', 'UPLIFT_SYMFONY' => 'major', 'UPLIFT_PACKAGES' => 'major', 'UPLIFT_PHP_MAX' => '8.1']);

        self::assertSame([
            'php apply' => '8.1.0 -> 8.1.33 patch',
            'php blocked' => '8.1.0 -> 8.4.14 minor',
            'symfony/* apply' => '6.4.10 -> 6.4.26 patch',
            'symfony/* blocked' => '6.4.10 -> 8.0.5 major',
            'monolog/monolog apply' => '2.9.1 -> 3.9.0 major',
            'acencyril/fineprint-bundle apply' => '0.1.0 -> 0.2.0 minor',
            'acencyril/fineprint-bundle blocked' => '0.1.0 -> 0.3.0 minor',
        ], self::table($plan));
        self::assertSame('above UPLIFT_PHP_MAX=8.1', self::move($plan, 'php', 'blocked')->reason);
        self::assertSame('requires PHP >=8.4, above UPLIFT_PHP_MAX=8.1', self::move($plan, 'symfony/*', 'blocked')->reason);
        self::assertSame('requires PHP >=8.2, above UPLIFT_PHP_MAX=8.1', self::move($plan, 'acencyril/fineprint-bundle', 'blocked')->reason);
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
