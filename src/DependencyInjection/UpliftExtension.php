<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\DependencyInjection;

use Acencyril\UpliftBundle\Apply\Applier;
use Acencyril\UpliftBundle\Command\ApplyCommand;
use Acencyril\UpliftBundle\Source\PackagistSource;
use Acencyril\UpliftBundle\Source\PhpNetSource;
use Acencyril\UpliftBundle\Uplift;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class UpliftExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config')))->load('services.php');

        $container->getDefinition(PackagistSource::class)->setArgument(1, $config['packagist_url']);
        $container->getDefinition(PhpNetSource::class)->setArgument(1, $config['php_releases_url']);
        $container->getDefinition(Uplift::class)->setArgument(3, $config['project_dir'])->setArgument(4, $config['composer']);
        $container->getDefinition(Applier::class)->setArgument(1, $config['composer']);
        $container->getDefinition(ApplyCommand::class)->setArgument(2, $config['report_dir']);
    }

    public function getAlias(): string
    {
        return 'uplift';
    }
}
