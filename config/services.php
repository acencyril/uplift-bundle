<?php

declare(strict_types=1);

use Acencyril\UpliftBundle\Apply\Applier;
use Acencyril\UpliftBundle\Command\ApplyCommand;
use Acencyril\UpliftBundle\Command\PlanCommand;
use Acencyril\UpliftBundle\Plan\Planner;
use Acencyril\UpliftBundle\Policy;
use Acencyril\UpliftBundle\Runner\ProcessRunner;
use Acencyril\UpliftBundle\Runner\Runner;
use Acencyril\UpliftBundle\Source\PackagistSource;
use Acencyril\UpliftBundle\Source\PhpNetSource;
use Acencyril\UpliftBundle\Source\PhpReleaseSource;
use Acencyril\UpliftBundle\Source\ReleaseSource;
use Acencyril\UpliftBundle\Uplift;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $c): void {
    $services = $c->services()->defaults()->autoconfigure(false)->autowire(false);

    // The policy is read when a command runs, from the UPLIFT_* environment variables.
    $services->set(Policy::class)->factory([Policy::class, 'fromRuntime']);

    $services->set(PackagistSource::class)->args([service('http_client')->nullOnInvalid(), 'https://repo.packagist.org']);
    $services->alias(ReleaseSource::class, PackagistSource::class);
    $services->set(PhpNetSource::class)->args([service('http_client')->nullOnInvalid(), 'https://www.php.net']);
    $services->alias(PhpReleaseSource::class, PhpNetSource::class);

    $services->set(ProcessRunner::class);
    $services->alias(Runner::class, ProcessRunner::class);

    $services->set(Planner::class)->args([service(ReleaseSource::class), service(PhpReleaseSource::class)]);
    $services->set(Uplift::class)->args([service(Planner::class), service(Runner::class), service(Policy::class), '%kernel.project_dir%', 'composer']);
    $services->set(Applier::class)->args([service(Runner::class), 'composer']);

    $services->set(PlanCommand::class)->args([service(Uplift::class)])->tag('console.command', ['command' => 'uplift:plan']);
    $services->set(ApplyCommand::class)->args([service(Uplift::class), service(Applier::class), '%kernel.project_dir%/var/uplift'])
        ->tag('console.command', ['command' => 'uplift:apply']);
};
