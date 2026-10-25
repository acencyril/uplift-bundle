<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Where things are. What may move is the policy, read from the UPLIFT_* environment
 * variables (see Policy), so that each server decides without a code change.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('uplift');
        $tree->getRootNode()
            ->children()
                ->scalarNode('project_dir')->defaultValue('%kernel.project_dir%')->info('the directory holding composer.json')->end()
                ->scalarNode('report_dir')->defaultValue('%kernel.project_dir%/var/uplift')->info('where report.json and report.md are written')->end()
                ->scalarNode('composer')->defaultValue('composer')->info('the composer binary')->end()
                ->scalarNode('packagist_url')->defaultValue('https://repo.packagist.org')->end()
                ->scalarNode('php_releases_url')->defaultValue('https://www.php.net')->end()
            ->end();

        return $tree;
    }
}
