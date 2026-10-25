<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

use Acencyril\UpliftBundle\Semver\Version;

/** One stable release of a package and the PHP it requires (null when unknown). */
final class Release
{
    public function __construct(public readonly Version $version, public readonly ?string $php = null)
    {
    }
}
