<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

use Acencyril\UpliftBundle\Semver\Version;

/**
 * What `composer outdated --direct --format=json` said: the latest release of each
 * direct dependency, and whether it is abandoned. A second opinion next to Packagist,
 * and the only one when Packagist cannot be reached.
 */
final class OutdatedReport
{
    /** @param array<string, array{latest: ?Version, abandoned: bool|string}> $packages */
    public function __construct(private readonly array $packages = [])
    {
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (\is_array($data['installed'] ?? null) ? $data['installed'] : [] as $p) {
            if (!\is_array($p) || !isset($p['name'])) {
                continue;
            }
            $abandoned = $p['abandoned'] ?? false;
            $out[strtolower((string) $p['name'])] = [
                'latest' => Version::parse((string) ($p['latest'] ?? '')),
                'abandoned' => \is_string($abandoned) ? $abandoned : (bool) $abandoned,
            ];
        }

        return new self($out);
    }

    public function latest(string $package): ?Version
    {
        return $this->packages[strtolower($package)]['latest'] ?? null;
    }

    /** False, true, or the name of the package to use instead. */
    public function abandoned(string $package): bool|string
    {
        return $this->packages[strtolower($package)]['abandoned'] ?? false;
    }
}
