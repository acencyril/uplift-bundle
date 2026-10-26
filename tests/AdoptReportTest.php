<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Adopt\Adopter;
use Acencyril\UpliftBundle\Adopt\AdoptionPlan;
use Acencyril\UpliftBundle\Adopt\AdoptionResult;
use Acencyril\UpliftBundle\Adopt\Catalog;
use Acencyril\UpliftBundle\Adopt\Catalog\AsCommandAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\LockAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\MapRequest;
use Acencyril\UpliftBundle\Adopt\Catalog\ReadonlyPromotion;
use Acencyril\UpliftBundle\Adopt\Scanner;
use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Report\ReportWriter;
use Acencyril\UpliftBundle\Runner\RunResult;
use Acencyril\UpliftBundle\Tests\Fixtures\FakeRunner;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedPhpSource;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedReleaseSource;
use PHPUnit\Framework\TestCase;

final class AdoptReportTest extends TestCase
{
    private string $dir;
    private \DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/uplift-adopt-report-'.bin2hex(random_bytes(4));
        AdopterTest::copy($this->dir);
        mkdir($this->dir.'/vendor/bin', 0o777, true);
        file_put_contents($this->dir.'/vendor/bin/rector', '');
        $this->at = new \DateTimeImmutable('2026-11-01 04:17:00 UTC');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** @return array{AdoptionPlan, AdoptionResult} as-command applied, readonly-promotion reverted, map-request manual */
    private function adopt(): array
    {
        $adopter = new Adopter(new Catalog([new MapRequest(), new AsCommandAttribute(), new ReadonlyPromotion(), new LockAttribute()]), new Scanner(),
            new FakeRunner(['make test' => [0, new RunResult(1, "FAILURES!\nTests: 3, Failures: 1.")]], ['vendor/bin/rector' => AdopterTest::rector()]));
        $project = new ComposerProject($this->dir);
        $plan = $adopter->find($project, Policy::fromEnv(['UPLIFT_ADOPT' => 'apply', 'UPLIFT_TEST_COMMAND' => 'make test']), ['src'], 'en', '8.3.12');

        return [$plan, $adopter->apply($project, $plan)];
    }

    public function testTheAdoptionsGoIntoTheSameReport(): void
    {
        $site = sys_get_temp_dir().'/uplift-adopt-site-'.bin2hex(random_bytes(4));
        mkdir($site);
        copy(__DIR__.'/Fixtures/site/composer.json', $site.'/composer.json');
        copy(__DIR__.'/Fixtures/site/composer.lock', $site.'/composer.lock');
        $movePlan = (new Planner(FixedReleaseSource::standard(), new FixedPhpSource()))->plan(new ComposerProject($site), Policy::fromEnv([]), null, '8.3.12');
        exec('rm -rf '.escapeshellarg($site));
        $out = $this->dir.'/out';
        ReportWriter::write($out, $movePlan, null, $this->at, 'site');

        [$plan, $result] = $this->adopt();
        ReportWriter::writeAdoptions($out, $plan, $result, $this->at, 'site');
        ReportWriter::writeAdoptions($out, $plan, $result, $this->at, 'site');

        $json = json_decode((string) file_get_contents($out.'/report.json'), true);
        self::assertSame('planned', $json['status'], 'the move report is kept');
        self::assertNotEmpty($json['moves']);
        self::assertSame('apply', $json['adoptions']['policy']['adopt']);
        self::assertSame(['as-command', 'lock-attribute', 'map-request', 'readonly-promotion'], array_column($json['adoptions']['findings'], 'id'));
        self::assertSame('applied', $json['adoptions']['result']['outcomes']['as-command']['status']);
        self::assertSame('reverted', $json['adoptions']['result']['outcomes']['readonly-promotion']['status']);
        self::assertSame(['file' => 'src/Command/LegacyCommand.php', 'line' => 11, 'snippet' => "protected static \$defaultName = 'app:legacy';", 'hint' => '#[AsCommand] on the class'],
            $json['adoptions']['findings'][0]['candidates'][0]);

        $md = (string) file_get_contents($out.'/report.md');
        self::assertStringStartsWith('# Uplift report: site', $md);
        self::assertSame(1, substr_count($md, '## New features'), 'a second run replaces the section');
        self::assertStringContainsString('### Map the request to typed arguments: manual', $md);
        self::assertStringContainsString('| `src/Controller/ApiController.php:28` | `$page = $request->query->getInt(\'page\', 1);` | #[MapQueryParameter] $page |', $md);
        self::assertStringContainsString('**Result: reverted**', $md);
        self::assertStringContainsString('FAILURES!', $md);
        self::assertStringContainsString('- A lock attribute on controller actions (experimental) (`lock-attribute`): needs symfony/framework-bundle >= 8.2, installed 7.4.1', $md);
    }

    public function testWithoutAMoveReportTheAdoptionsStandAlone(): void
    {
        [$plan] = $this->adopt();
        $files = ReportWriter::writeAdoptions($this->dir.'/out', $plan, null, $this->at, 'site');
        $json = json_decode((string) file_get_contents($files['json']), true);
        self::assertSame('adoptions', $json['status']);
        self::assertNull($json['adoptions']['result']);
        self::assertStringStartsWith("# Uplift report: site\n", (string) file_get_contents($files['markdown']));
    }

    public function testOneRehearsalChangePerAdoption(): void
    {
        $file = $this->dir.'/.rehearsal/changes.json';
        mkdir(\dirname($file));
        file_put_contents($file, json_encode(['title' => 'November', 'changes' => [['id' => 'uplift-2026-11-01', 'title' => 'Dependency upgrades (3)']]]));
        [$plan, $result] = $this->adopt();

        self::assertSame(['uplift-adopt-as-command', 'uplift-adopt-map-request', 'uplift-adopt-readonly-promotion'], ReportWriter::adoptionsToRehearsal($file, $plan, $result, $this->at, 'abc1234'));
        ReportWriter::adoptionsToRehearsal($file, $plan, $result, $this->at, 'abc1234');

        $data = json_decode((string) file_get_contents($file), true);
        self::assertSame('November', $data['title']);
        self::assertSame(['uplift-2026-11-01', 'uplift-adopt-as-command', 'uplift-adopt-map-request', 'uplift-adopt-readonly-promotion'], array_column($data['changes'], 'id'), 'kept, and replaced not duplicated');
        [, $command, $map, $readonly] = $data['changes'];

        self::assertSame(['id', 'title', 'detail', 'replaced', 'check', 'url', 'source', 'revision', 'tests', 'proposed_at'], array_keys($command));
        self::assertSame('New feature: Name commands with #[AsCommand]', $command['title']);
        self::assertSame('uplift', $command['source']);
        self::assertSame('abc1234', $command['revision']);
        self::assertSame('', $command['url'], 'a docs link is not a page of the site');
        self::assertSame(['status' => 'passed', 'summary' => 'make test', 'output' => 'ok: make test'], $command['tests']);
        self::assertStringContainsString('Rewritten by Rector, the tests passed.', $command['detail']);
        self::assertStringContainsString("Places (2):\n- src/Command/LegacyCommand.php:11:", $command['detail']);
        self::assertStringContainsString('Docs: https://symfony.com/doc/current/console.html', $command['detail']);

        self::assertSame('failed', $readonly['tests']['status']);
        self::assertStringContainsString('the tests failed: put back', $readonly['detail']);
        self::assertSame('not_run', $map['tests']['status']);
        self::assertStringContainsString('Proposed, not applied (no automatic rewrite', $map['detail']);
        self::assertSame('2026-11-01T04:17:00+00:00', $map['proposed_at']);
        foreach ($data['changes'] as $c) {
            self::assertMatchesRegularExpression('/^[a-z0-9._-]{1,64}$/', $c['id'], 'a valid Rehearsal id');
        }
    }

    public function testNoCandidatesNoRehearsalChange(): void
    {
        $plan = new AdoptionPlan(Policy::fromEnv([]), []);
        self::assertSame([], ReportWriter::adoptionsToRehearsal($this->dir.'/none.json', $plan, null, $this->at));
        self::assertFileDoesNotExist($this->dir.'/none.json');
    }
}
