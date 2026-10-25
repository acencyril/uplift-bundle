<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests\Fixtures;

use Acencyril\UpliftBundle\Semver\Version;
use Acencyril\UpliftBundle\Source\PhpBranch;
use Acencyril\UpliftBundle\Source\PhpReleaseSource;

final class FixedPhpSource implements PhpReleaseSource
{
    /** @param array<string, bool> $latest latest release => still supported */
    public function __construct(private readonly array $latest = ['8.1.33' => false, '8.2.29' => true, '8.3.27' => true, '8.4.14' => true], private readonly bool $unreachable = false)
    {
    }

    public function branches(array $alsoBranches = []): array
    {
        if ($this->unreachable) {
            throw new \RuntimeException('php.net down');
        }
        $out = [];
        foreach ($this->latest as $v => $supported) {
            $version = Version::of((string) $v);
            if ($supported || \in_array($version->branch(), $alsoBranches, true)) {
                $out[] = new PhpBranch($version, $supported);
            }
        }

        return $out;
    }
}
