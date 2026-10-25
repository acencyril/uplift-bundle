<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

/** Where the PHP releases come from: php.net in production, a fixed list in tests. */
interface PhpReleaseSource
{
    /**
     * The supported branches, plus any of $alsoBranches (e.g. "8.1", the branch the
     * project is on) with their latest release even when no longer supported.
     *
     * @param list<string> $alsoBranches
     *
     * @return list<PhpBranch>
     */
    public function branches(array $alsoBranches = []): array;
}
