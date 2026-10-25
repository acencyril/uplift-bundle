<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests\Fixtures;

use Acencyril\UpliftBundle\Semver\Version;
use Acencyril\UpliftBundle\Source\Release;
use Acencyril\UpliftBundle\Source\ReleaseSource;

/** Releases from a fixed table: package => [version => required PHP or null]. No network. */
final class FixedReleaseSource implements ReleaseSource
{
    /** @param array<string, array<string, ?string>> $table */
    public function __construct(private readonly array $table = [], private readonly bool $unreachable = false)
    {
    }

    public function releases(string $package): array
    {
        if ($this->unreachable) {
            throw new \RuntimeException('network down');
        }
        $out = [];
        foreach ($this->table[$package] ?? [] as $version => $php) {
            $out[] = new Release(Version::of((string) $version), $php);
        }

        return $out;
    }

    /** What Packagist would say for the two fixture projects, as of a made-up autumn. */
    public static function standard(): self
    {
        $symfony = ['7.4.1' => '>=8.2', '7.4.8' => '>=8.2', '8.0.0' => '>=8.4', '8.0.5' => '>=8.4', '6.4.10' => '>=8.1', '6.4.26' => '>=8.1', '7.0.0' => '>=8.2'];

        return new self([
            'symfony/framework-bundle' => $symfony,
            'acencyril/sentinelle-bundle' => ['0.3.1' => '>=8.1', '0.3.4' => '>=8.1', '0.4.0' => '>=8.1', '1.0.0' => '>=8.2'],
            'acencyril/fineprint-bundle' => ['0.1.0' => '>=8.1', '0.2.0' => '>=8.1', '0.3.0' => '>=8.2'],
            'doctrine/orm' => ['3.3.1' => '^8.1', '3.3.4' => '^8.1', '3.5.2' => '^8.1', '4.0.0' => '^8.4'],
            'symfony/flex' => ['2.8.0' => '>=8.1', '2.9.0' => '>=8.1'],
            'twig/twig' => ['3.21.0' => '>=8.1.0', '3.21.1' => '>=8.1.0'],
            'phpunit/phpunit' => ['11.5.2' => '>=8.2', '11.5.40' => '>=8.2', '12.4.0' => '>=8.3'],
            'monolog/monolog' => ['2.9.1' => '>=7.2', '2.10.0' => '>=7.2', '3.9.0' => '>=8.1'],
        ]);
    }
}
