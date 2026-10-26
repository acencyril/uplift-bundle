<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Level;
use Acencyril\UpliftBundle\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testSafeDefaults(): void
    {
        $p = Policy::fromEnv([]);
        self::assertFalse($p->enabled);
        self::assertSame(Level::Patch, $p->php);
        self::assertSame(Level::Patch, $p->symfony);
        self::assertSame(Level::Patch, $p->packages);
        self::assertNull($p->phpMax);
        self::assertSame(['acencyril'], $p->ownVendors);
        self::assertSame('vendor/bin/phpunit', $p->testCommand);
        self::assertSame('propose', $p->adopt);
        self::assertSame([], $p->adoptSkip);
    }

    public function testAdoptionPolicy(): void
    {
        self::assertSame('off', Policy::fromEnv(['UPLIFT_ADOPT' => 'off'])->adopt);
        self::assertSame('apply', Policy::fromEnv(['UPLIFT_ADOPT' => ' Apply '])->adopt);
        self::assertSame('propose', Policy::fromEnv(['UPLIFT_ADOPT' => ''])->adopt);
        self::assertSame(['as-command', 'map-request'], Policy::fromEnv(['UPLIFT_ADOPT_SKIP' => 'As-Command, ,map-request'])->adoptSkip);
        self::assertSame(['adopt' => 'apply', 'adopt_skip' => ['x']], array_intersect_key(Policy::fromEnv(['UPLIFT_ADOPT' => 'apply', 'UPLIFT_ADOPT_SKIP' => 'x'])->toArray(), ['adopt' => 1, 'adopt_skip' => 1]));
    }

    public function testEveryVariable(): void
    {
        $p = Policy::fromEnv([
            'UPLIFT_ENABLED' => '1', 'UPLIFT_PHP' => 'minor', 'UPLIFT_SYMFONY' => 'major', 'UPLIFT_PACKAGES' => 'off',
            'UPLIFT_PHP_MAX' => '8.1', 'UPLIFT_OWN_VENDOR' => 'Acencyril, other', 'UPLIFT_TEST_COMMAND' => 'make test',
        ]);
        self::assertTrue($p->enabled);
        self::assertSame(Level::Minor, $p->php);
        self::assertSame(Level::Major, $p->symfony);
        self::assertSame(Level::Off, $p->packages);
        self::assertSame('8.1', $p->phpMax);
        self::assertSame(['acencyril', 'other'], $p->ownVendors);
        self::assertTrue($p->isOwn('Other/thing'));
        self::assertSame('make test', $p->testCommand);
        self::assertSame('', Policy::fromEnv(['UPLIFT_TEST_COMMAND' => ''])->testCommand, 'an empty command means no tests');
    }

    public function testOwnPackagesMoveUpToAMinorWhateverThePackagesLevel(): void
    {
        self::assertSame(Level::Minor, Policy::fromEnv(['UPLIFT_PACKAGES' => 'off'])->levelFor('own'));
        self::assertSame(Level::Minor, Policy::fromEnv([])->levelFor('own'));
        self::assertSame(Level::Major, Policy::fromEnv(['UPLIFT_PACKAGES' => 'major'])->levelFor('own'));
        self::assertSame([], Policy::fromEnv(['UPLIFT_OWN_VENDOR' => 'none'])->ownVendors);
        self::assertFalse(Policy::fromEnv(['UPLIFT_OWN_VENDOR' => ''])->isOwn('acencyril/x'));
    }

    /** @return iterable<array{array<string, string>}> */
    public static function wrong(): iterable
    {
        yield [['UPLIFT_PHP' => 'all']];
        yield [['UPLIFT_ENABLED' => 'yes']];
        yield [['UPLIFT_PHP_MAX' => '8']];
        yield [['UPLIFT_PHP_MAX' => '8.1.2']];
        yield [['UPLIFT_ADOPT' => 'yes']];
    }

    /** @param array<string, string> $env */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrong')]
    public function testAWrongValueIsAnErrorNotADefault(array $env): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Policy::fromEnv($env);
    }
}
