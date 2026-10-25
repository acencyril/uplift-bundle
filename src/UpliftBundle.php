<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Uplift — dependency upgrades with guardrails: PHP, Symfony and packages move as far
 * as the policy allows, the tests decide, and a report says what happened. It never
 * pushes or deploys.
 */
class UpliftBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
