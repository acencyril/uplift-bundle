<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Composer\ComposerProject;
use Acencyril\UpliftBundle\Composer\ConstraintRewriter;
use Acencyril\UpliftBundle\Composer\JsonEditor;
use Acencyril\UpliftBundle\Semver\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerTest extends TestCase
{
    /** @return iterable<array{string, string, ?string}> */
    public static function rewrites(): iterable
    {
        yield 'symfony line' => ['7.4.*', '8.0.5', '8.0.*'];
        yield '0.x caret' => ['^0.3', '0.4.2', '^0.4'];
        yield 'caret, three numbers' => ['^2.13.1', '3.1.2', '^3.1.2'];
        yield 'caret, kept when allowed' => ['^2.13', '2.14.0', '^2.13'];
        yield 'tilde' => ['~2.13.1', '3.0.2', '~3.0.2'];
        yield 'major wildcard' => ['2.*', '3.4.0', '3.*'];
        yield 'exact' => ['1.2', '1.3.5', '1.3.5'];
        yield 'or: one more alternative' => ['^6.4 || ^7.0', '8.0.1', '^6.4 || ^7.0 || ^8.0'];
        yield 'bounded range: by hand' => ['>=1.0 <2.0', '2.1.0', null];
        yield 'branch: by hand' => ['dev-main', '1.0.0', null];
    }

    #[DataProvider('rewrites')]
    public function testConstraintsAreWidenedTheWayTheyWereWritten(string $from, string $target, ?string $expected): void
    {
        self::assertSame($expected, ConstraintRewriter::rewrite($from, Version::of($target)));
    }

    public function testThePhpRequirementIsRaisedInItsOwnForm(): void
    {
        self::assertSame('>=8.4', ConstraintRewriter::raisePhp('>=8.3', Version::of('8.4.14')));
        self::assertSame('^8.4', ConstraintRewriter::raisePhp('^8.1', Version::of('8.4.14')));
        self::assertSame('~8.4.0', ConstraintRewriter::raisePhp('~8.3.0', Version::of('8.4.14')));
        self::assertSame('8.4.*', ConstraintRewriter::raisePhp('8.3.*', Version::of('8.4.14')));
        self::assertNull(ConstraintRewriter::raisePhp('>=8.1 <8.4', Version::of('8.4.14')));
    }

    public function testTheEditorChangesOneValueAndNothingElse(): void
    {
        $json = (string) file_get_contents(__DIR__.'/Fixtures/site/composer.json');
        $new = JsonEditor::replace($json, ['require', 'symfony/console'], '8.0.*');
        self::assertNotNull($new);
        self::assertSame(str_replace('"symfony/console": "7.4.*"', '"symfony/console": "8.0.*"', $json), $new);
        // "{}" stays "{}", nothing is re-indented
        self::assertStringContainsString('"scripts": {},', $new);

        $platform = JsonEditor::replace($json, ['config', 'platform', 'php'], '8.4.14');
        self::assertSame(str_replace('"php": "8.3.12"', '"php": "8.4.14"', $json), $platform);
        self::assertSame(str_replace('"require": "7.4.*"', '"require": "8.0.*"', $json), JsonEditor::replace($json, ['extra', 'symfony', 'require'], '8.0.*'));
    }

    public function testTheEditorOnlyTouchesTheSectionItIsGiven(): void
    {
        $json = "{\n    \"require\": {\"a/b\": \"^1.0\"},\n    \"conflict\": {\"a/b\": \"^1.0\"}\n}\n";
        self::assertSame("{\n    \"require\": {\"a/b\": \"^1.0\"},\n    \"conflict\": {\"a/b\": \"<1.2\"}\n}\n", JsonEditor::replace($json, ['conflict', 'a/b'], '<1.2'));
        self::assertNull(JsonEditor::replace($json, ['require-dev', 'a/b'], '^2.0'));
        self::assertNull(JsonEditor::replace($json, ['require'], '^2.0'), 'only a string is replaced');
    }

    public function testTheProjectIsRead(): void
    {
        $p = new ComposerProject(__DIR__.'/Fixtures/site');
        self::assertSame('0.3.1', (string) $p->installed('acencyril/sentinelle-bundle'));
        self::assertSame('11.5.2', (string) $p->installed('phpunit/phpunit'));
        self::assertTrue($p->requirements()['phpunit/phpunit']['dev']);
        self::assertArrayNotHasKey('ext-intl', $p->requirements());
        self::assertArrayNotHasKey('php', $p->requirements());
        self::assertSame('require-dev', $p->section('symfony/browser-kit'));
        self::assertSame('7.4.*', $p->symfonyRequire());
        self::assertSame('8.3.12', (string) $p->phpVersion('8.4.1'), 'config.platform.php first');
    }

    public function testThePhpVersionWithoutPlatform(): void
    {
        $dir = sys_get_temp_dir().'/uplift-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/composer.json', '{"require": {"php": ">=8.2"}}');
        $p = new ComposerProject($dir);
        self::assertSame('8.2.29', (string) $p->phpVersion('8.2.29'), 'the runtime when it is on the floor branch');
        self::assertSame('8.2.0', (string) $p->phpVersion('8.4.1'), 'else the floor');
        unlink($dir.'/composer.json');
        rmdir($dir);
    }
}
