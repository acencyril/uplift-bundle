<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Adopt\Adopter;
use Acencyril\UpliftBundle\Adopt\AdoptionPlan;
use Acencyril\UpliftBundle\Adopt\Catalog;
use Acencyril\UpliftBundle\Adopt\Catalog\AsCommandAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\LockAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\MapRequest;
use Acencyril\UpliftBundle\Adopt\Catalog\ReadonlyPromotion;
use Acencyril\UpliftBundle\Adopt\Finding;
use Acencyril\UpliftBundle\Adopt\Scanner;
use Acencyril\UpliftBundle\Adopt\Texts;
use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Runner\RunResult;
use Acencyril\UpliftBundle\Tests\Fixtures\FakeRunner;
use PHPUnit\Framework\TestCase;

final class AdopterTest extends TestCase
{
    public const FEED = 'src/Service/Feed.php';
    public const LEGACY = 'src/Command/LegacyCommand.php';

    private string $dir;
    /** @var list<string> the Rector configs seen */
    private array $configs = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/uplift-adopt-'.bin2hex(random_bytes(4));
        exec('cp -r '.escapeshellarg(__DIR__.'/Fixtures/adopt').' '.escapeshellarg($this->dir));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public static function copy(string $dir): void
    {
        exec('cp -r '.escapeshellarg(__DIR__.'/Fixtures/adopt').' '.escapeshellarg($dir));
    }

    /** A fake Rector: rewrites what the real rules would, for the files given in its config. */
    public static function rector(?array &$configs = null): callable
    {
        return static function (string $cwd, string $line) use (&$configs): void {
            preg_match('/--config=(\S+)/', $line, $m);
            $config = (string) file_get_contents($m[1]);
            $configs[] = $config;
            $edit = static function (string $file, array $map) use ($cwd, $config): void {
                if (str_contains($config, $cwd.'/'.$file)) {
                    file_put_contents($cwd.'/'.$file, strtr((string) file_get_contents($cwd.'/'.$file), $map));
                }
            };
            if (str_contains($config, 'ReadOnlyPropertyRector')) {
                $edit(self::FEED, ['private HttpClientInterface' => 'private readonly HttpClientInterface', 'private string $url' => 'private readonly string $url']);
                $edit('src/Controller/ApiController.php', ['private LockFactory' => 'private readonly LockFactory']);
            }
            if (str_contains($config, 'CommandPropertyToAttributeRector')) {
                $edit(self::LEGACY, ['class LegacyCommand' => "#[\\Symfony\\Component\\Console\\Attribute\\AsCommand('app:legacy')]\nclass LegacyCommand"]);
            }
        };
    }

    private function adopter(FakeRunner $runner): Adopter
    {
        return new Adopter(new Catalog([new MapRequest(), new AsCommandAttribute(), new ReadonlyPromotion(), new LockAttribute()]), new Scanner(), $runner, new Texts());
    }

    /** @param array<string, string> $env */
    private function find(array $env, ?FakeRunner $runner = null, string $locale = 'en'): AdoptionPlan
    {
        return $this->adopter($runner ?? new FakeRunner())->find(new ComposerProject($this->dir), Policy::fromEnv($env), ['src'], $locale, '8.3.12');
    }

    /** @return array<string, string> id => decision */
    private static function decisions(AdoptionPlan $plan): array
    {
        $out = [];
        foreach ($plan->findings as $f) {
            $out[$f->id()] = $f->decision;
        }

        return $out;
    }

    private function lock(string $package, string $version): void
    {
        $lock = json_decode((string) file_get_contents($this->dir.'/composer.lock'), true);
        $lock['packages'] = array_values(array_filter($lock['packages'], static fn (array $p) => $p['name'] !== $package));
        if ('' !== $version) {
            $lock['packages'][] = ['name' => $package, 'version' => $version];
        }
        file_put_contents($this->dir.'/composer.lock', json_encode($lock));
    }

    private function withRector(): void
    {
        mkdir($this->dir.'/vendor/bin', 0o777, true);
        file_put_contents($this->dir.'/vendor/bin/rector', '');
    }

    public function testOffLooksForNothing(): void
    {
        $plan = $this->find(['UPLIFT_ADOPT' => 'off']);
        self::assertSame([], $plan->findings);
        self::assertSame(['UPLIFT_ADOPT=off: new features are not looked for.'], $plan->notes);
        $runner = new FakeRunner();
        self::assertSame('off', $this->adopter($runner)->apply(new ComposerProject($this->dir), $plan)->status);
        self::assertSame([], $runner->commands);
    }

    public function testProposeIsTheDefault(): void
    {
        $plan = $this->find([]);
        self::assertSame(['as-command' => 'propose', 'lock-attribute' => 'later', 'map-request' => 'manual', 'readonly-promotion' => 'propose'], self::decisions($plan));
        $lock = $plan->withDecision('later')[0];
        self::assertSame('needs symfony/framework-bundle >= 8.2, installed 7.4.1', $lock->reason);
        self::assertSame('Map the request to typed arguments', $plan->withDecision('manual')[0]->title);
        self::assertSame('8.3.12', $plan->withDecision('propose')[1]->installed, 'PHP: config.platform.php');
        self::assertSame(10, $plan->files);
        self::assertCount(1, $plan->notes, 'the file that does not parse');
    }

    public function testApplyOnlyChangesTheDecisionOfAdoptionsWithARectorRule(): void
    {
        $plan = $this->find(['UPLIFT_ADOPT' => 'apply']);
        self::assertSame(['as-command' => 'apply', 'lock-attribute' => 'later', 'map-request' => 'manual', 'readonly-promotion' => 'apply'], self::decisions($plan));
    }

    public function testAnExperimentalAdoptionIsNeverApplied(): void
    {
        $this->lock('symfony/framework-bundle', 'v8.2.0');
        $plan = $this->find(['UPLIFT_ADOPT' => 'apply']);
        $lock = array_values(array_filter($plan->findings, static fn (Finding $f) => 'lock-attribute' === $f->id()))[0];
        self::assertSame('propose', $lock->decision);
        self::assertStringContainsString('experimental', $lock->reason);
        self::assertCount(2, $lock->candidates);
    }

    public function testSkippedAdoptionsAreNotScanned(): void
    {
        $plan = $this->find(['UPLIFT_ADOPT_SKIP' => 'readonly-promotion, Map-Request']);
        self::assertSame(['as-command' => 'propose', 'lock-attribute' => 'later', 'map-request' => 'skipped', 'readonly-promotion' => 'skipped'], self::decisions($plan));
        foreach ($plan->withDecision('skipped') as $f) {
            self::assertSame([], $f->candidates);
            self::assertSame('in UPLIFT_ADOPT_SKIP', $f->reason);
        }
    }

    public function testTheInstalledVersionsDecide(): void
    {
        $this->lock('symfony/http-kernel', 'v6.2.14');
        $this->lock('symfony/console', '');
        $json = json_decode((string) file_get_contents($this->dir.'/composer.json'), true);
        $json['config']['platform']['php'] = '8.0.30';
        file_put_contents($this->dir.'/composer.json', json_encode($json));

        $plan = $this->find([]);
        self::assertSame(['as-command' => 'later', 'lock-attribute' => 'later', 'map-request' => 'later', 'readonly-promotion' => 'later'], self::decisions($plan));
        self::assertSame(['needs symfony/console >= 5.3, not installed', 'needs symfony/framework-bundle >= 8.2, installed 7.4.1', 'needs symfony/http-kernel >= 6.3, installed 6.2.14', 'needs php >= 8.1, installed 8.0.30'],
            array_map(static fn (Finding $f) => $f->reason, $plan->findings));
        self::assertSame(0, $plan->files, 'nothing to scan for');
    }

    public function testNothingFoundIsNone(): void
    {
        $plan = $this->adopter(new FakeRunner())->find(new ComposerProject($this->dir), Policy::fromEnv([]), ['src/Entity'], 'en', '8.3.12');
        self::assertSame('none', self::decisions($plan)['readonly-promotion']);
    }

    public function testFrenchTexts(): void
    {
        $plan = $this->find([], null, 'fr');
        self::assertSame('Associer la requête à des arguments typés', $plan->withDecision('manual')[0]->title);
    }

    public function testApplyRewritesEachAdoptionThenRunsTheTests(): void
    {
        $this->withRector();
        $runner = new FakeRunner([], ['vendor/bin/rector' => self::rector($this->configs)]);
        $adopter = $this->adopter($runner);
        $project = new ComposerProject($this->dir);
        $plan = $adopter->find($project, Policy::fromEnv(['UPLIFT_ADOPT' => 'apply', 'UPLIFT_TEST_COMMAND' => 'make test']), ['src'], 'en', '8.3.12');
        $result = $adopter->apply($project, $plan);

        self::assertSame('done', $result->status);
        self::assertSame('applied', $result->outcomes['as-command']['status']);
        self::assertSame([self::LEGACY], $result->outcomes['as-command']['files']);
        self::assertSame('applied', $result->outcomes['readonly-promotion']['status']);
        self::assertSame(['src/Controller/ApiController.php', self::FEED], $result->outcomes['readonly-promotion']['files'], 'the subscriber was a candidate but Rector left it');
        self::assertArrayNotHasKey('map-request', $result->outcomes, 'manual');
        self::assertStringContainsString('private readonly string $url', (string) file_get_contents($this->dir.'/'.self::FEED));
        self::assertStringContainsString('AsCommand', (string) file_get_contents($this->dir.'/'.self::LEGACY));

        $rector = array_values(array_filter($runner->commands, static fn (string $c) => str_starts_with($c, 'vendor/bin/rector')));
        self::assertCount(2, $rector, 'one Rector run per adoption');
        self::assertSame(2, \count(array_filter($runner->commands, static fn (string $c) => 'make test' === $c)), 'tests after each');
        self::assertStringContainsString("->withRules([\\Rector\\Symfony\\Symfony61\\Rector\\Class_\\CommandPropertyToAttributeRector::class])", $this->configs[0]);
        self::assertStringContainsString("->withPaths(['".$this->dir.'/'.self::LEGACY."'])", $this->configs[0], 'only the candidate files');
        self::assertSame([], glob(sys_get_temp_dir().'/uplift-adopt-*.php') ?: [], 'the temporary configs are deleted');
        self::assertFalse($runner->ran('git'), 'no commit unless asked');
    }

    public function testFailingTestsPutTheFilesBack(): void
    {
        $this->withRector();
        $runner = new FakeRunner(['make test' => [0, 1]], ['vendor/bin/rector' => self::rector()]);
        $adopter = $this->adopter($runner);
        $project = new ComposerProject($this->dir);
        $plan = $adopter->find($project, Policy::fromEnv(['UPLIFT_ADOPT' => 'apply', 'UPLIFT_TEST_COMMAND' => 'make test']), ['src'], 'en', '8.3.12');
        $result = $adopter->apply($project, $plan, ['commit' => true]);

        self::assertSame('applied', $result->outcomes['as-command']['status']);
        self::assertSame('reverted', $result->outcomes['readonly-promotion']['status']);
        self::assertSame('failed', $result->outcomes['readonly-promotion']['steps']['tests']['status']);
        self::assertFileEquals(__DIR__.'/Fixtures/adopt/'.self::FEED, $this->dir.'/'.self::FEED);
        self::assertFileEquals(__DIR__.'/Fixtures/adopt/src/Controller/ApiController.php', $this->dir.'/src/Controller/ApiController.php');
        self::assertStringContainsString('AsCommand', (string) file_get_contents($this->dir.'/'.self::LEGACY), 'the passing adoption stays');
        self::assertContains('readonly-promotion: the tests failed, 2 file(s) put back.', $result->notes);
        self::assertSame(['as-command'], $result->kept());
        self::assertTrue($runner->ran('git add -- '.self::LEGACY));
        self::assertTrue($runner->ran('git commit -q -m Uplift: adopt as-command -- '.self::LEGACY), 'only the kept files');
    }

    public function testAFailingRectorPutsTheFilesBack(): void
    {
        $this->withRector();
        $runner = new FakeRunner(['vendor/bin/rector' => new RunResult(1, 'Class "Rector\Php81\Rector\Property\ReadOnlyPropertyRector" not found')], ['vendor/bin/rector' => self::rector()]);
        $adopter = $this->adopter($runner);
        $project = new ComposerProject($this->dir);
        $result = $adopter->apply($project, $adopter->find($project, Policy::fromEnv(['UPLIFT_ADOPT' => 'apply', 'UPLIFT_ADOPT_SKIP' => 'as-command']), ['src'], 'en', '8.3.12'));

        self::assertSame('rector_failed', $result->outcomes['readonly-promotion']['status']);
        self::assertFileEquals(__DIR__.'/Fixtures/adopt/'.self::FEED, $this->dir.'/'.self::FEED);
        self::assertFalse($runner->ran('vendor/bin/phpunit'));
    }

    public function testRectorChangingNothingIsUnchangedAndUntestedWithoutATestCommand(): void
    {
        $this->withRector();
        $runner = new FakeRunner([], ['vendor/bin/rector' => self::rector()]);
        $adopter = $this->adopter($runner);
        $project = new ComposerProject($this->dir);
        file_put_contents($this->dir.'/'.self::LEGACY, str_replace('class LegacyCommand', 'class  LegacyCommand', (string) file_get_contents($this->dir.'/'.self::LEGACY)));
        $result = $adopter->apply($project, $adopter->find($project, Policy::fromEnv(['UPLIFT_ADOPT' => 'apply', 'UPLIFT_TEST_COMMAND' => '']), ['src'], 'en', '8.3.12'));

        self::assertSame('unchanged', $result->outcomes['as-command']['status'], 'the fake Rector does not recognise it any more');
        self::assertSame('untested', $result->outcomes['readonly-promotion']['status']);
        self::assertSame(['readonly-promotion'], $result->kept());
    }

    public function testProposeOrNoRectorRewritesNothing(): void
    {
        $runner = new FakeRunner();
        $adopter = $this->adopter($runner);
        $project = new ComposerProject($this->dir);

        $result = $adopter->apply($project, $adopter->find($project, Policy::fromEnv([]), ['src'], 'en', '8.3.12'));
        self::assertSame('proposed', $result->status);
        self::assertSame(['UPLIFT_ADOPT=propose: nothing rewritten.'], $result->notes);

        $result = $adopter->apply($project, $adopter->find($project, Policy::fromEnv(['UPLIFT_ADOPT' => 'apply']), ['src'], 'en', '8.3.12'));
        self::assertSame('proposed', $result->status);
        self::assertStringStartsWith('Rector is not installed', $result->notes[0]);
        self::assertSame([], $runner->commands);
    }
}
