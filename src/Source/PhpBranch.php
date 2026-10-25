<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

use Acencyril\UpliftBundle\Semver\Version;

/** A PHP branch (8.3), its latest release (8.3.27) and whether php.net still supports it. */
final class PhpBranch
{
    public function __construct(public readonly Version $latest, public readonly bool $supported)
    {
    }

    public function branch(): string
    {
        return $this->latest->branch();
    }
}
