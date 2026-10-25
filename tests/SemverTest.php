<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Level;
use Acencyril\UpliftBundle\Semver\Constraint;
use Acencyril\UpliftBundle\Semver\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemverTest extends TestCase
{
    public function testVersionsAreReadTheComposerWay(): void
    {
        self::assertSame('7.4.3', (string) Version::of('v7.4.3'));
        self::assertSame('7.4.3', (string) Version::of('7.4.3.0'));
        self::assertSame('8.1.0', (string) Version::of('8.1'));
        self::assertSame(2, Version::of('8.1')->segments);
        self::assertNull(Version::parse('7.4.0-RC1'));
        self::assertNull(Version::parse('dev-main'));
        self::assertSame('8.4', Version::of('8.4.14')->format(2));
    }

    public function testLevels(): void
    {
        self::assertSame(Level::Patch, Version::of('7.4.1')->levelTo(Version::of('7.4.8')));
        self::assertSame(Level::Minor, Version::of('0.3.1')->levelTo(Version::of('0.4.0')));
        self::assertSame(Level::Major, Version::of('7.4.8')->levelTo(Version::of('8.0.0')));
        self::assertNull(Version::of('7.4.8')->levelTo(Version::of('7.4.8')));
        self::assertNull(Version::of('7.4.8')->levelTo(Version::of('7.4.1')));
    }

    /** @return iterable<array{string, string, bool}> */
    public static function constraints(): iterable
    {
        yield ['^0.3', '0.3.9', true];
        yield ['^0.3', '0.4.0', false];
        yield ['^0.0.3', '0.0.4', false];
        yield ['^2.13', '2.99.0', true];
        yield ['^2.13', '3.0.0', false];
        yield ['~2.13', '2.99.0', true];
        yield ['~2.13.1', '2.14.0', false];
        yield ['7.4.*', '7.4.30', true];
        yield ['7.4.*', '7.5.0', false];
        yield ['7.*', '7.9.0', true];
        yield ['>=8.3', '9.0.0', true];
        yield ['>= 8.3', '8.2.9', false];
        yield ['>=8.1 <8.4', '8.4.0', false];
        yield ['>=8.1,<8.4', '8.3.27', true];
        yield ['^6.4 || ^7.0', '7.4.0', true];
        yield ['^6.4 | ^7.0', '8.0.0', false];
        yield ['1.0 - 2.1', '2.1.9', true];
        yield ['1.0 - 2.1', '2.2.0', false];
        yield ['3.3.1', '3.3.1', true];
        yield ['3.3.1', '3.3.2', false];
        yield ['!=3.3.1', '3.3.2', true];
        yield ['*', '42.0.0', true];
        yield ['^3.0@stable', '3.5.0', true];
    }

    #[DataProvider('constraints')]
    public function testConstraints(string $constraint, string $version, bool $accepted): void
    {
        self::assertSame($accepted, Constraint::parse($constraint)?->accepts(Version::of($version)), "$constraint / $version");
    }

    public function testWhatIsNotAConstraint(): void
    {
        self::assertNull(Constraint::parse('dev-main'));
        self::assertNull(Constraint::parse('self.version'));
        self::assertNull(Constraint::parse(''));
    }

    public function testLowerBounds(): void
    {
        self::assertSame('8.3.0', (string) Constraint::parse('>=8.3')?->lowerBound());
        self::assertSame('8.1.0', (string) Constraint::parse('^8.2 || ^8.1')?->lowerBound());
        self::assertSame('8.2.0', (string) Constraint::parse('8.2.*')?->lowerBound());
        self::assertSame('0.0.0', (string) Constraint::parse('*')?->lowerBound());
    }
}
