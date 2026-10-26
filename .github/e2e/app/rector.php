<?php

// The project's own Rector configuration: uplift:apply runs it after the version move.
use Rector\Config\RectorConfig;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src/Controller'])
    ->withSets([SymfonySetList::SYMFONY_64]);
