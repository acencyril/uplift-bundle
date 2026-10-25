<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

/**
 * Where the published versions of a package come from. The real one asks Packagist;
 * tests give a fixed list. Throw when the source cannot be reached: the plan notes it
 * and carries on with what composer outdated said.
 */
interface ReleaseSource
{
    /** @return list<Release> the stable releases, in any order */
    public function releases(string $package): array;
}
