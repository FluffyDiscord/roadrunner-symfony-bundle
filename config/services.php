<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Factory\RPCFactory;
use FluffyDiscord\RoadRunnerBundle\Session\WorkerSessionStorageFactory;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker as BundleHttpWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\CentrifugoWorkerInterface;
use RoadRunner\Centrifugo\Request\RequestFactory;
use RoadRunner\Centrifugo\RPCCentrifugoApi;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
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
            false,
            service(KernelInterface::class),
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

    // Worker sessions fix
    $services
        ->set(WorkerSessionStorageFactory::class)
        ->args([
            param('session.storage.options'),
            service('session.handler'),
            inline_service(MetadataBag::class)
                ->args([
                    param('session.metadata.storage_key'),
                    param('session.metadata.update_threshold'),
                ]),
            service(RequestStack::class),
            false,
        ])
    ;

    // Centrifugo
    if (class_exists(RoadRunnerCentrifugoWorker::class)) {
        $services
            ->set(RequestFactory::class)
            ->args([
                service(RoadRunnerWorkerInterface::class),
            ])
        ;

        $services
            ->set(CentrifugoWorkerInterface::class, RoadRunnerCentrifugoWorker::class)
            ->args([
                service(RoadRunnerWorkerInterface::class),
                service(RequestFactory::class),
            ])
        ;

        $services
            ->set(RPCCentrifugoApi::class)
            ->public()
            ->args([
                service(RPCInterface::class),
            ])
        ;

        $services
            ->set(CentrifugoWorker::class)
            ->public()
            ->args([
                false,
                service(KernelInterface::class),
                service(CentrifugoWorkerInterface::class),
                service(EventDispatcherInterface::class),
                service(SentryHubInterface::class)->nullOnInvalid(),
            ])
        ;

        $services
            ->get(WorkerRegistry::class)
            ->call("registerWorker", [
                Environment\Mode::MODE_CENTRIFUGE,
                service(CentrifugoWorker::class),
            ])
        ;
    }
};
