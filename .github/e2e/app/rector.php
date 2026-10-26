<?php

// The project's own Rector configuration: uplift:apply runs it after the version move.
// Composer-based sets: the Symfony rules up to the installed version.
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src/Controller'])
    ->withComposerBased(symfony: true);
