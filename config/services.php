<?php

declare(strict_types=1);

use Acencyril\UpliftBundle\Adopt\Adopter;
use Acencyril\UpliftBundle\Adopt\Adoption;
use Acencyril\UpliftBundle\Adopt\Catalog;
use Acencyril\UpliftBundle\Adopt\Catalog\AsCommandAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\LockAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\MapRequest;
use Acencyril\UpliftBundle\Adopt\Catalog\ReadonlyPromotion;
use Acencyril\UpliftBundle\Adopt\Scanner;
use Acencyril\UpliftBundle\Adopt\Texts;
use Acencyril\UpliftBundle\Apply\Applier;
use Acencyril\UpliftBundle\Command\AdoptCommand;
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
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

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

    // New features: one class per adoption, tagged; a site adds its own (autoconfigured).
    foreach ([MapRequest::class, AsCommandAttribute::class, ReadonlyPromotion::class, LockAttribute::class] as $adoption) {
        $services->set($adoption)->tag(Adoption::TAG);
    }
    $services->set(Catalog::class)->args([tagged_iterator(Adoption::TAG)]);
    $services->set(Scanner::class);
    $services->set(Texts::class)->args([service('translator')->nullOnInvalid()]);
    $services->set(Adopter::class)->args([service(Catalog::class), service(Scanner::class), service(Runner::class), service(Texts::class)]);

    $services->set(PlanCommand::class)->args([service(Uplift::class)])->tag('console.command', ['command' => 'uplift:plan']);
    $services->set(ApplyCommand::class)->args([service(Uplift::class), service(Applier::class), '%kernel.project_dir%/var/uplift', service(Adopter::class), ['src']])
        ->tag('console.command', ['command' => 'uplift:apply']);
    $services->set(AdoptCommand::class)->args([service(Uplift::class), service(Adopter::class), '%kernel.project_dir%/var/uplift', ['src']])
        ->tag('console.command', ['command' => 'uplift:adopt']);
};
