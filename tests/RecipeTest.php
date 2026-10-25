<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\DependencyInjection\Configuration;
use Acencyril\UpliftBundle\Policy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

final class RecipeTest extends TestCase
{
    private const DIR = __DIR__.'/../recipe';

    public function testTheManifestRegistersTheBundleAndThePolicy(): void
    {
        $manifest = json_decode((string) file_get_contents(self::DIR.'/manifest.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['all'], $manifest['bundles']['Acencyril\\UpliftBundle\\UpliftBundle']);
        self::assertSame('%CONFIG_DIR%/', $manifest['copy-from-recipe']['config/']);
        $env = array_filter($manifest['env'], static fn (string $k) => !str_starts_with($k, '#'), \ARRAY_FILTER_USE_KEY);
        self::assertSame(['UPLIFT_ENABLED', 'UPLIFT_PHP', 'UPLIFT_SYMFONY', 'UPLIFT_PACKAGES', 'UPLIFT_PHP_MAX', 'UPLIFT_OWN_VENDOR', 'UPLIFT_TEST_COMMAND'], array_keys($env));
        self::assertEquals(new Policy(), Policy::fromEnv($env), 'the recipe writes the safe defaults');
    }

    public function testTheConfigurationIsValid(): void
    {
        $yaml = Yaml::parseFile(self::DIR.'/config/packages/uplift.yaml');
        $config = (new Processor())->processConfiguration(new Configuration(), [$yaml['uplift']]);
        self::assertSame('%kernel.project_dir%/var/uplift', $config['report_dir']);
    }

    public function testRecipeYamlIsIndentedByFourSpaces(): void
    {
        foreach (glob(self::DIR.'/config/*/*.yaml') ?: [] as $file) {
            foreach (file($file, \FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
                $indent = \strlen($line) - \strlen(ltrim($line, ' '));
                self::assertSame(0, $indent % 4, basename($file).':'.($n + 1));
                self::assertDoesNotMatchRegularExpression('/^\s*- \S/', $line, basename($file).':'.($n + 1).': a list item goes alone on its line');
            }
        }
    }
}
