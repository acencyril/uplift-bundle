<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests\Fixtures;

use Acencyril\UpliftBundle\Runner\Runner;
use Acencyril\UpliftBundle\Source\PhpReleaseSource;
use Acencyril\UpliftBundle\Source\ReleaseSource;
use Acencyril\UpliftBundle\UpliftBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A bare application with Uplift, its project dir pointed at a copy of a fixture, and
 * the network and the shell replaced by fixed sources and FakeRunner.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug, private readonly string $project = '')
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new UpliftBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2).'/var/cache/'.$this->environment.'-'.md5($this->project);
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2).'/var/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false, 'php_errors' => ['log' => true]]);
        $container->register('logger', NullLogger::class);
        $container->loadFromExtension('uplift', ['project_dir' => $this->project, 'report_dir' => $this->project.'/var/uplift']);

        $container->register('test.releases', FixedReleaseSource::class)->setFactory([FixedReleaseSource::class, 'standard']);
        $container->setAlias(ReleaseSource::class, 'test.releases');
        $container->register('test.php', FixedPhpSource::class);
        $container->setAlias(PhpReleaseSource::class, 'test.php');
        $container->register(FakeRunner::class)->setPublic(true);
        $container->setAlias(Runner::class, FakeRunner::class)->setPublic(true);
    }
}
