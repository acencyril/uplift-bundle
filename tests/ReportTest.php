<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Apply\Applier;
use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Report\ReportWriter;
use Acencyril\UpliftBundle\Tests\Fixtures\FakeRunner;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedPhpSource;
use Acencyril\UpliftBundle\Tests\Fixtures\FixedReleaseSource;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/uplift-report-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(__DIR__.'/Fixtures/legacy/composer.json', $this->dir.'/composer.json');
        copy(__DIR__.'/Fixtures/legacy/composer.lock', $this->dir.'/composer.lock');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testJsonMarkdownAndRehearsal(): void
    {
        $project = new ComposerProject($this->dir);
        $policy = Policy::fromEnv(['UPLIFT_ENABLED' => '1', 'UPLIFT_PHP_MAX' => '8.1', 'UPLIFT_SYMFONY' => 'major']);
        $plan = (new Planner(FixedReleaseSource::standard(), new FixedPhpSource()))->plan($project, $policy, null, '8.1.2');
        $result = (new Applier(new FakeRunner()))->apply($project, $plan);
        $at = new \DateTimeImmutable('2026-11-02 04:00:00 UTC');

        $files = ReportWriter::write($this->dir.'/var/uplift', $plan, $result, $at, 'legacy');
        $json = json_decode((string) file_get_contents($files['json']), true);
        self::assertSame('uplift', $json['tool']);
        self::assertSame('passed', $json['status']);
        self::assertSame('8.1', $json['php']['max']);
        self::assertSame('blocked', $json['moves'][1]['decision']);
        self::assertSame('above UPLIFT_PHP_MAX=8.1', $json['moves'][1]['reason']);
        self::assertSame('passed', $json['result']['steps']['tests']['status']);

        $md = (string) file_get_contents($files['markdown']);
        self::assertStringContainsString('# Uplift report: legacy', $md);
        self::assertStringContainsString('## Applied', $md);
        self::assertStringContainsString('| symfony/* (2 packages) | 6.4.10 | 6.4.26 | patch | 6.4.* |  |', $md);
        self::assertStringContainsString('## Blocked', $md);
        self::assertStringContainsString('requires PHP >=8.2, above UPLIFT_PHP_MAX=8.1', $md);
        self::assertStringContainsString("## The way ahead\n", $md);
        self::assertStringContainsString('- symfony/*: 6.4.10 -> 6.4.26 now; then 7.4.8, 8.0.5 in later runs', $md);
        self::assertSame('6.4.10 -> 6.4.26 now; then 7.4.8, 8.0.5 in later runs', $json['paths']['symfony/*']);
        self::assertSame(['7.4.8', '8.0.5'], $json['moves'][2]['later']);
        self::assertStringContainsString('PHP 8.1 is no longer supported by php.net', $md);

        $file = $this->dir.'/.rehearsal/changes.json';
        file_put_contents($this->dir.'/x', '');
        mkdir(\dirname($file));
        file_put_contents($file, json_encode(['title' => 'Kept', 'changes' => [['id' => 'other', 'title' => 'Other'], ['id' => 'uplift-2026-11-02', 'title' => 'Old run']]]));
        $id = ReportWriter::toRehearsal($file, $plan, $result, $at);
        self::assertSame('uplift-2026-11-02', $id);
        $data = json_decode((string) file_get_contents($file), true);
        self::assertSame('Kept', $data['title']);
        self::assertSame(['other', 'uplift-2026-11-02'], array_column($data['changes'], 'id'), 'the same day replaces its own change only');
        $change = $data['changes'][1];
        self::assertSame('uplift', $change['source']);
        self::assertSame('passed', $change['tests']['status']);
        self::assertStringContainsString("Moved:\n- php 8.1.0 -> 8.1.33 (patch)\n- symfony/* 6.4.10 -> 6.4.26 (patch)", $change['detail']);
        self::assertStringContainsString("\n\nBlocked:\n- php 8.1.0 -> 8.2.29 (minor): above UPLIFT_PHP_MAX=8.1", $change['detail']);
        self::assertStringContainsString("\n\nThe way ahead:\n- symfony/*: 6.4.10 -> 6.4.26 now; then 7.4.8, 8.0.5 in later runs", $change['detail']);
    }

    public function testOutputThatIsNotUtf8StillMakesAReport(): void
    {
        $project = new ComposerProject($this->dir);
        $plan = (new Planner(FixedReleaseSource::standard(), new FixedPhpSource()))->plan($project, Policy::fromEnv(['UPLIFT_ENABLED' => '1']), null, '8.1.2');
        $result = (new Applier(new FakeRunner()))->apply($project, $plan);
        $result->step('tests', 'passed', 'vendor/bin/phpunit', 0, "OK \xC3 (cut inside a character)");

        $files = ReportWriter::write($this->dir.'/var/uplift', $plan, $result, new \DateTimeImmutable(), 'legacy');
        $json = json_decode((string) file_get_contents($files['json']), true);

        self::assertIsArray($json, 'report.json is valid JSON');
        self::assertStringContainsString("OK \u{FFFD} (cut", $json['result']['steps']['tests']['output']);
    }

    public function testAPlanAlone(): void
    {
        $plan = (new Planner(FixedReleaseSource::standard(), new FixedPhpSource()))->plan(new ComposerProject($this->dir), Policy::fromEnv([]), null, '8.1.2');
        $md = ReportWriter::markdown($plan, null, new \DateTimeImmutable('2026-11-02'));
        self::assertStringContainsString('status **planned**', $md);
        self::assertStringContainsString('## To apply', $md);
        self::assertStringContainsString('UPLIFT_ENABLED is not 1', $md);
    }
}
