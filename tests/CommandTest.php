<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Tests\Fixtures\FakeRunner;
use Acencyril\UpliftBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandTest extends TestCase
{
    private const VARS = Policy::VARIABLES;
    private string $dir;
    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/uplift-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(__DIR__.'/Fixtures/site/composer.json', $this->dir.'/composer.json');
        copy(__DIR__.'/Fixtures/site/composer.lock', $this->dir.'/composer.lock');
        file_put_contents($this->dir.'/outdated.json', '{"installed": []}');
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $k) {
            unset($_SERVER[$k], $_ENV[$k]);
        }
        $this->kernel?->shutdown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @param array<string, string> $env */
    private function command(string $name, array $env = []): CommandTester
    {
        foreach ($env as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $this->kernel = new TestKernel('test', true, $this->dir);
        $app = new Application($this->kernel);

        return new CommandTester($app->find($name));
    }

    public function testBothCommandsAreRegistered(): void
    {
        $this->kernel = new TestKernel('test', true, $this->dir);
        $app = new Application($this->kernel);
        self::assertTrue($app->has('uplift:plan'));
        self::assertTrue($app->has('uplift:apply'));
        self::assertTrue($app->has('uplift:adopt'));
        self::assertStringContainsString('Never pushes, never deploys', $app->find('uplift:apply')->getDescription());
    }

    public function testPlanAsATable(): void
    {
        $tester = $this->command('uplift:plan');
        self::assertSame(0, $tester->execute(['--outdated' => $this->dir.'/outdated.json']));
        $out = $tester->getDisplay();
        self::assertStringContainsString('acencyril/sentinelle-bundle', $out);
        self::assertStringContainsString('^0.3 -> ^0.4', $out);
        self::assertStringContainsString('UPLIFT_ENABLED is not 1', $out);
    }

    public function testPlanAsJsonRunsComposerOutdated(): void
    {
        $tester = $this->command('uplift:plan', ['UPLIFT_PHP_MAX' => '8.3']);
        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true);
        self::assertSame('planned', $data['status']);
        self::assertSame('8.3', $data['policy']['php_max']);
        self::assertSame('above UPLIFT_PHP_MAX=8.3', $data['moves'][1]['reason']);
        $runner = $this->kernel?->getContainer()->get(FakeRunner::class);
        self::assertSame(['composer outdated --direct --format=json --no-interaction'], $runner->commands);
    }

    public function testPlanRefusesAnUnknownFormat(): void
    {
        self::assertSame(2, $this->command('uplift:plan')->execute(['--format' => 'xml', '--no-outdated' => true]));
    }

    public function testApplyDisabledChangesNothing(): void
    {
        $tester = $this->command('uplift:apply');
        self::assertSame(0, $tester->execute(['--no-outdated' => true]));
        self::assertStringContainsString('UPLIFT_ENABLED is not 1: nothing changed.', $tester->getDisplay());
        self::assertFileEquals(__DIR__.'/Fixtures/site/composer.json', $this->dir.'/composer.json');
        self::assertFileExists($this->dir.'/var/uplift/report.json');
    }

    public function testApplyWritesTheReportAndTheRehearsalChange(): void
    {
        $tester = $this->command('uplift:apply', ['UPLIFT_ENABLED' => '1', 'UPLIFT_TEST_COMMAND' => 'make test']);
        self::assertSame(0, $tester->execute(['--no-outdated' => true, '--rehearsal' => $this->dir.'/.rehearsal/changes.json', '--report-dir' => $this->dir.'/out']));
        self::assertStringContainsString('move(s) applied, tests passed.', $tester->getDisplay());
        self::assertSame('passed', json_decode((string) file_get_contents($this->dir.'/out/report.json'), true)['status']);
        $changes = json_decode((string) file_get_contents($this->dir.'/.rehearsal/changes.json'), true);
        self::assertSame('uplift', $changes['changes'][0]['source']);
        $summary = $changes['changes'][0]['summary'];
        self::assertSame('Symfony', $summary['main']['label']);
        self::assertSame(['after' => 'passed'], $summary['tests']);
        self::assertSame(1, $summary['constraints_rewritten'], 'require.acencyril/sentinelle-bundle (the platform PHP is not a constraint)');
        self::assertIsInt($summary['packages_moved']);
        self::assertStringContainsString('"acencyril/sentinelle-bundle": "^0.4"', (string) file_get_contents($this->dir.'/composer.json'));
    }

    public function testApplyFailsLoudlyOnFailedTests(): void
    {
        $tester = $this->command('uplift:apply', ['UPLIFT_ENABLED' => '1', 'UPLIFT_TEST_COMMAND' => 'false']);
        $runner = $this->kernel?->getContainer()->get(FakeRunner::class);
        $ref = new \ReflectionProperty($runner, 'answers');
        $ref->setValue($runner, ['false' => 1]);
        self::assertSame(1, $tester->execute(['--no-outdated' => true]));
        self::assertStringContainsString('the tests FAILED', $tester->getDisplay());
    }

    public function testABadPolicyIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->command('uplift:plan', ['UPLIFT_PHP' => 'everything'])->execute(['--no-outdated' => true]);
    }
}
