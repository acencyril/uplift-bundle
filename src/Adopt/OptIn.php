<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/**
 * An adoption that is off unless the site asks for it: its id must be listed in
 * UPLIFT_ADOPT_OPT_IN. For a rewrite that is correct PHP but can break code that works
 * behind PHP's back (reflection, hydrators). Until opted in, it is reported as skipped;
 * once opted in, it follows UPLIFT_ADOPT like any other adoption, even when experimental.
 */
interface OptIn
{
}
