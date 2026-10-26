<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Apply\Applier;
use Acencyril\UpliftBundle\Apply\ApplyResult;
use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Runner\RunResult;
use Acencyril\UpliftBundle\Tests\Fixtures\FakeRunner;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedPhpSource;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedReleaseSource;
use PHPUnit\Framework\TestCase;

final class ApplierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/uplift-apply-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $env */
    private function apply(string $fixture, array $env, FakeRunner $runner, array $options = []): ApplyResult
    {
        copy(__DIR__.'/Fixtures/'.$fixture.'/composer.json', $this->dir.'/composer.json');
        copy(__DIR__.'/Fixtures/'.$fixture.'/composer.lock', $this->dir.'/composer.lock');
        $project = new ComposerProject($this->dir);
        $plan = (new Planner(FixedReleaseSource::standard(), new FixedPhpSource()))->plan($project, Policy::fromEnv($env), null, '8.3.12');

        return (new Applier($runner))->apply($project, $plan, $options);
    }

    private function diff(string $fixture): array
    {
        $before = file(__DIR__.'/Fixtures/'.$fixture.'/composer.json', \FILE_IGNORE_NEW_LINES) ?: [];
        $after = file($this->dir.'/composer.json', \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(\count($before), $after, 'no line added or removed');

        return array_values(array_filter(array_map(static fn ($a, $b) => $a === $b ? null : trim($a).' => '.trim($b), $before, $after)));
    }

    public function testDisabledTouchesNothing(): void
    {
        $runner = new FakeRunner();
        $result = $this->apply('site', [], $runner);
        self::assertSame('disabled', $result->status);
        self::assertSame([], $runner->commands);
        self::assertSame([], $this->diff('site'));
    }

    public function testPatchesAndOwnMinorsAreAppliedThenTested(): void
    {
        $runner = new FakeRunner();
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1'], $runner);

        self::assertSame('passed', $result->status);
        self::assertSame([
            '"acencyril/sentinelle-bundle": "^0.3", => "acencyril/sentinelle-bundle": "^0.4",',
            '"php": "8.3.12" => "php": "8.3.27"',
        ], $this->diff('site'), 'only the constraints past their ceiling, and the pinned platform');
        self::assertSame([
            'composer update symfony/console symfony/framework-bundle symfony/yaml symfony/browser-kit acencyril/sentinelle-bundle doctrine/orm twig/twig phpunit/phpunit'
            .' --with acencyril/sentinelle-bundle:0.4.0 --with doctrine/orm:3.3.4 --with twig/twig:3.21.1 --with phpunit/phpunit:11.5.40 --with-dependencies --no-interaction --no-progress',
            'vendor/bin/phpunit',
        ], $runner->commands);
        self::assertSame('passed', $result->steps['tests']['status']);
        self::assertSame('skipped', $result->steps['rector']['status']);
        self::assertSame(['config.platform.php: 8.3.12 -> 8.3.27', 'require.acencyril/sentinelle-bundle: ^0.3 -> ^0.4'], $result->rewrites);
    }

    public function testMajorsRewriteSymfonyAndPhp(): void
    {
        $runner = new FakeRunner();
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1', 'UPLIFT_PHP' => 'minor', 'UPLIFT_SYMFONY' => 'major', 'UPLIFT_PACKAGES' => 'major'], $runner);

        self::assertSame('passed', $result->status);
        self::assertSame([
            '"php": ">=8.3", => "php": ">=8.4",',
            '"acencyril/sentinelle-bundle": "^0.3", => "acencyril/sentinelle-bundle": "^1.0",',
            '"doctrine/orm": "^3.3", => "doctrine/orm": "^4.0",',
            '"symfony/console": "7.4.*", => "symfony/console": "8.0.*",',
            '"symfony/framework-bundle": "7.4.*", => "symfony/framework-bundle": "8.0.*",',
            '"symfony/yaml": "7.4.*", => "symfony/yaml": "8.0.*",',
            '"phpunit/phpunit": "^11.5", => "phpunit/phpunit": "^12.4",',
            '"symfony/browser-kit": "7.4.*" => "symfony/browser-kit": "8.0.*"',
            '"php": "8.3.12" => "php": "8.4.14"',
            '"require": "7.4.*" => "require": "8.0.*"',
        ], $this->diff('site'));
        self::assertStringContainsString('--with symfony/flex:2.9.0', $runner->commands[0]);
        // The note only appears when the steps ran on another PHP than the one now targeted.
        $note = array_filter($result->notes, static fn (string $n) => str_contains($n, 'targets PHP 8.4'));
        if (\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION === '8.4') {
            self::assertEmpty($note);
        } else {
            self::assertNotEmpty($note);
        }
    }

    public function testWhenComposerRefusesTheRewritesTheSimpleMovesAreTriedAlone(): void
    {
        $runner = new FakeRunner(['composer update' => [1, 0]]);
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1'], $runner);

        self::assertSame('passed', $result->status);
        self::assertCount(2, array_filter($runner->commands, static fn (string $c) => str_contains($c, 'composer update')));
        self::assertStringNotContainsString('sentinelle', $runner->commands[1]);
        self::assertSame(['acencyril/sentinelle-bundle'], array_map(static fn ($m) => $m->package, $result->dropped));
        self::assertSame(['"php": "8.3.12" => "php": "8.3.27"'], $this->diff('site'));
    }

    public function testWhenComposerFailsEverythingIsPutBack(): void
    {
        $runner = new FakeRunner(['composer update' => 1]);
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1'], $runner);

        self::assertSame('composer_failed', $result->status);
        self::assertSame([], $this->diff('site'));
        self::assertFileEquals(__DIR__.'/Fixtures/site/composer.lock', $this->dir.'/composer.lock');
        self::assertFalse($runner->ran('vendor/bin/phpunit'), 'no tests on a failed update');
        self::assertSame([], $result->rewrites);
    }

    public function testFailingTestsAreReported(): void
    {
        $runner = new FakeRunner(['make test' => new RunResult(2, "1 test failed\nFAILURES!")]);
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1', 'UPLIFT_TEST_COMMAND' => 'make test'], $runner);

        self::assertSame('tests_failed', $result->status);
        self::assertSame(2, $result->steps['tests']['exit_code']);
        self::assertStringContainsString('FAILURES!', $result->steps['tests']['output']);
    }

    public function testNoTestCommandMeansUntested(): void
    {
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1', 'UPLIFT_TEST_COMMAND' => ''], new FakeRunner());
        self::assertSame('untested', $result->status);
    }

    public function testABranchAndACommit(): void
    {
        $runner = new FakeRunner(['git status' => new RunResult(0, ''), 'git rev-parse' => new RunResult(0, "abc1234\n")]);
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1'], $runner, ['branch' => 'upgrade/2026-11', 'commit' => true]);

        self::assertSame('passed', $result->status);
        self::assertSame('upgrade/2026-11', $result->branch);
        self::assertSame('abc1234', $result->commit);
        self::assertSame('git switch -c upgrade/2026-11', $runner->commands[1]);
        self::assertTrue($runner->ran('git commit -q -m Uplift: php 8.3.27'));
        self::assertFalse($runner->ran('git push'), 'never');
    }

    public function testACommitNeedsACleanTree(): void
    {
        $runner = new FakeRunner(['git status' => new RunResult(0, " M src/Kernel.php\n")]);
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1'], $runner, ['commit' => true]);
        self::assertSame('refused', $result->status);
        self::assertFalse($runner->ran('composer'));
    }

    public function testABadBranchNameIsRefused(): void
    {
        $runner = new FakeRunner();
        $result = $this->apply('site', ['UPLIFT_ENABLED' => '1'], $runner, ['branch' => '../main']);
        self::assertSame('refused', $result->status);
        self::assertSame([], $runner->commands);
    }

    public function testPhpPatchWithoutAFullPlatformVersionOnlyUpdatesTheLockHash(): void
    {
        $runner = new FakeRunner();
        $result = $this->apply('legacy', ['UPLIFT_ENABLED' => '1', 'UPLIFT_SYMFONY' => 'off', 'UPLIFT_PACKAGES' => 'off', 'UPLIFT_OWN_VENDOR' => 'none', 'UPLIFT_PHP_MAX' => '8.1'], $runner);

        self::assertSame('passed', $result->status);
        self::assertSame([], $this->diff('legacy'));
        self::assertStringStartsWith('composer update --lock', $runner->commands[0]);
        self::assertContains('PHP 8.1.33: nothing to change in composer.json, the runtime image picks the release up.', $result->notes);
    }
}
