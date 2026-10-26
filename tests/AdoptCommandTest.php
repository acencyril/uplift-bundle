<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Tests\Fixtures\FakeRunner;
use Acencyril\UpliftBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AdoptCommandTest extends TestCase
{
    private string $dir;
    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/uplift-adopt-cmd-'.bin2hex(random_bytes(4));
        AdopterTest::copy($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (Policy::VARIABLES as $k) {
            unset($_SERVER[$k], $_ENV[$k]);
        }
        $this->kernel?->shutdown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @param array<string, string> $env */
    private function command(string $name, array $env = [], array $answers = []): CommandTester
    {
        foreach ($env as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $this->kernel = new TestKernel('test', true, $this->dir);
        $app = new Application($this->kernel);
        $tester = new CommandTester($app->find($name));
        $runner = $this->kernel->getContainer()->get(FakeRunner::class);
        (new \ReflectionProperty($runner, 'answers'))->setValue($runner, $answers);
        (new \ReflectionProperty($runner, 'effects'))->setValue($runner, ['vendor/bin/rector' => AdopterTest::rector()]);

        return $tester;
    }

    private function withRector(): void
    {
        mkdir($this->dir.'/vendor/bin', 0o777, true);
        file_put_contents($this->dir.'/vendor/bin/rector', '');
    }

    public function testAdoptListsTheCandidatesAndWritesTheReport(): void
    {
        $tester = $this->command('uplift:adopt');
        self::assertSame(0, $tester->execute([]));
        $out = $tester->getDisplay();
        self::assertStringContainsString('Map the request to typed arguments (map-request)', $out);
        self::assertStringContainsString('src/Controller/ApiController.php:20', $out);
        self::assertStringContainsString('src/Service/Broken.php does not parse', $out);
        self::assertStringContainsString('lock-attribute) [experimental]', $out);
        $json = json_decode((string) file_get_contents($this->dir.'/var/uplift/report.json'), true);
        self::assertSame('propose', $json['adoptions']['policy']['adopt']);
        self::assertNull($json['adoptions']['result']);
        self::assertFileEquals(__DIR__.'/Fixtures/adopt/src/Service/Feed.php', $this->dir.'/src/Service/Feed.php');
    }

    public function testAdoptApplyInFrenchWithRehearsal(): void
    {
        $this->withRector();
        $tester = $this->command('uplift:adopt', ['UPLIFT_ADOPT' => 'apply', 'UPLIFT_ADOPT_SKIP' => 'readonly-promotion']);
        self::assertSame(0, $tester->execute(['--apply' => true, '--locale' => 'fr', '--rehearsal' => $this->dir.'/.rehearsal/changes.json', '--path' => ['src/Command', 'src/Controller']]));
        $out = $tester->getDisplay();
        self::assertStringContainsString('Nommer les commandes avec #[AsCommand]', $out);
        self::assertStringContainsString('result: applied', $out);
        self::assertStringContainsString('Rehearsal: change "uplift-adopt-as-command"', $out);
        self::assertStringContainsString('AsCommand', (string) file_get_contents($this->dir.'/src/Command/LegacyCommand.php'));
        $changes = json_decode((string) file_get_contents($this->dir.'/.rehearsal/changes.json'), true)['changes'];
        self::assertSame(['uplift-adopt-as-command', 'uplift-adopt-map-request'], array_column($changes, 'id'));
        self::assertSame('New feature: Nommer les commandes avec #[AsCommand]', $changes[0]['title']);
    }

    public function testAdoptRefusesAnUnknownLocale(): void
    {
        self::assertSame(2, $this->command('uplift:adopt')->execute(['--locale' => 'de']));
    }

    public function testApplyChainsTheAdoptionsAfterAPassingMove(): void
    {
        copy(__DIR__.'/Fixtures/site/composer.json', $this->dir.'/composer.json');
        copy(__DIR__.'/Fixtures/site/composer.lock', $this->dir.'/composer.lock');
        $this->withRector();
        $tester = $this->command('uplift:apply', ['UPLIFT_ENABLED' => '1', 'UPLIFT_ADOPT' => 'apply', 'UPLIFT_TEST_COMMAND' => 'make test', 'UPLIFT_ADOPT_OPT_IN' => 'readonly-promotion']);
        self::assertSame(0, $tester->execute(['--no-outdated' => true, '--adopt' => true, '--rehearsal' => $this->dir.'/.rehearsal/changes.json']));

        $json = json_decode((string) file_get_contents($this->dir.'/var/uplift/report.json'), true);
        self::assertSame('passed', $json['status']);
        self::assertSame('done', $json['adoptions']['result']['status']);
        self::assertSame('applied', $json['adoptions']['result']['outcomes']['as-command']['status']);
        self::assertSame('applied', $json['adoptions']['result']['outcomes']['readonly-promotion']['status']);
        self::assertSame('later', array_column($json['adoptions']['findings'], 'decision', 'id')['map-request'], 'the site fixture has no symfony/http-kernel in its lock');
        $ids = array_column(json_decode((string) file_get_contents($this->dir.'/.rehearsal/changes.json'), true)['changes'], 'id');
        self::assertSame('uplift-'.date('Y-m-d'), $ids[0]);
        self::assertSame(['uplift-adopt-as-command', 'uplift-adopt-readonly-promotion'], \array_slice($ids, 1));
        $summary = json_decode((string) file_get_contents($this->dir.'/.rehearsal/changes.json'), true)['changes'][0]['summary'];
        self::assertSame(['passed'], array_values($summary['tests']));
        self::assertSame(['as-command' => 'applied', 'readonly-promotion' => 'applied'], array_column($summary['adoptions'], 'status', 'id'));
        $md = (string) file_get_contents($this->dir.'/var/uplift/report.md');
        self::assertStringContainsString('## Applied', $md);
        self::assertStringContainsString('## New features', $md);
    }

    public function testApplyDoesNotAdoptAfterFailedTests(): void
    {
        copy(__DIR__.'/Fixtures/site/composer.json', $this->dir.'/composer.json');
        copy(__DIR__.'/Fixtures/site/composer.lock', $this->dir.'/composer.lock');
        $this->withRector();
        $tester = $this->command('uplift:apply', ['UPLIFT_ENABLED' => '1', 'UPLIFT_ADOPT' => 'apply', 'UPLIFT_TEST_COMMAND' => 'make test', 'UPLIFT_ADOPT_OPT_IN' => 'readonly-promotion'], ['make test' => 1]);
        self::assertSame(1, $tester->execute(['--no-outdated' => true, '--adopt' => true]));
        self::assertStringContainsString('New features not looked for: the version move did not succeed.', $tester->getDisplay());
        self::assertArrayNotHasKey('adoptions', json_decode((string) file_get_contents($this->dir.'/var/uplift/report.json'), true));
        self::assertFileEquals(__DIR__.'/Fixtures/adopt/src/Command/LegacyCommand.php', $this->dir.'/src/Command/LegacyCommand.php');
    }

    public function testApplyDisabledOnlyProposesTheAdoptions(): void
    {
        copy(__DIR__.'/Fixtures/site/composer.json', $this->dir.'/composer.json');
        copy(__DIR__.'/Fixtures/site/composer.lock', $this->dir.'/composer.lock');
        $this->withRector();
        $tester = $this->command('uplift:apply', ['UPLIFT_ADOPT' => 'apply']);
        self::assertSame(0, $tester->execute(['--no-outdated' => true, '--adopt' => true]));
        $json = json_decode((string) file_get_contents($this->dir.'/var/uplift/report.json'), true);
        self::assertSame('disabled', $json['status']);
        self::assertNull($json['adoptions']['result']);
        self::assertSame('apply', array_column($json['adoptions']['findings'], 'decision', 'id')['as-command']);
        self::assertFileEquals(__DIR__.'/Fixtures/adopt/src/Command/LegacyCommand.php', $this->dir.'/src/Command/LegacyCommand.php');
    }
}
