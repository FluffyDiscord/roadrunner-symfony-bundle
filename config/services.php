<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Factory\RPCFactory;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker as BundleHttpWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Http\HttpWorkerInterface;
use Spiral\RoadRunner\Worker as RoadRunnerWorker;
use Spiral\RoadRunner\WorkerInterface as RoadRunnerWorkerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

return static function (ContainerConfigurator $container) {
    $services = $container->services();

    // default RoadRunner services
    $services
        ->set(EnvironmentInterface::class)
        ->factory([Environment::class, "fromGlobals"])
    ;

    $services
        ->set(RoadRunnerWorkerInterface::class)
        ->share(false)
        ->factory([RoadRunnerWorker::class, "createFromEnvironment"])
        ->args([
            service(EnvironmentInterface::class),
        ])
    ;

    $services
        ->set(HttpWorkerInterface::class, HttpWorker::class)
        ->args([
            service(RoadRunnerWorkerInterface::class),
        ])
    ;

    $services
        ->set(RPCInterface::class)
        ->factory([RPCFactory::class, "fromEnvironment"])
        ->args([
            service(EnvironmentInterface::class),
        ])
    ;

    // default bundle services
    $services
        ->set(WorkerRegistry::class)
        ->public()
    ;

    $services
        ->set(BundleHttpWorker::class)
        ->public()
        ->args([
            true,
            false,
            service(KernelInterface::class),
            service(EventDispatcherInterface::class),
            param('kernel.debug'),
            service("services_resetter")->nullOnInvalid(),
            service(SentryHubInterface::class)->nullOnInvalid(),
            service(HttpFoundationFactoryInterface::class)->nullOnInvalid(),
        ])
    ;

    $services
        ->get(WorkerRegistry::class)
        ->call("registerWorker", [
            Environment\Mode::MODE_HTTP,
            service(BundleHttpWorker::class),
        ])
    ;

    // Optional features — each file is a no-op unless its underlying package is installed.
    $container->import('temporal.php');
    $container->import('centrifugo.php');
    $container->import('jobs.php');
    $container->import('locks.php');
};
