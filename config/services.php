<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\EventListener\WorkerResponseSendEventListener;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCConnectionFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorkerFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker as BundleHttpWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\TemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Monolog\Logger as MonologLogger;
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
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Workflow\WorkflowInterface;

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
        ->set(WorkerResponseSendEventListener::class)
        ->public()
        ->args([
            service("services_resetter"),
            param("kernel.debug"),
        ])
        ->tag("kernel.event_listener", ["priority" => -256])
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

    // Temporal
    if (class_exists(WorkflowInterface::class)) {
        $services
            ->set(TemporalCollector::class)
            ->autoconfigure()
            ->autowire()
            ->tag('data_collector', [
                'id' => 'fluffy_discord.roadrunner.temporal',
            ])
        ;

        $services
            ->set(TemporalWorker::class)
            ->public()
            ->args([
                service(KernelInterface::class),
                service(EventDispatcherInterface::class),
                service(TemporalWorkerFactoryInterface::class),
                service(TemporalWorkerInitializer::class),
                service(SentryHubInterface::class)->nullOnInvalid(),
            ])
        ;

        $services
            ->set(TemporalWorkerInitializer::class)
            ->public()
            ->autowire()
            ->autoconfigure()
        ;

        $services
            ->set(RPCConnectionInterface::class)
            ->public()
            ->factory([RPCConnectionFactory::class, "fromEnvironment"])
            ->args([
                service(EnvironmentInterface::class),
            ])
        ;

        $services
            ->set(DefaultTemporalWorkerFactory::class)
            ->public()
            ->args([
                service(RPCConnectionInterface::class),
            ])
        ;

        $services->alias(TemporalWorkerFactoryInterface::class, DefaultTemporalWorkerFactory::class);

        $services
            ->set(DefaultTemporalWorker::class)
            ->public()
            ->args([
                service('monolog.logger.temporal')->nullOnInvalid(), // TODO: not working
            ])
        ;

        $services->alias(TemporalWorkerInterface::class, DefaultTemporalWorker::class);

        $services
            ->get(WorkerRegistry::class)
            ->call("registerWorker", [
                Environment\Mode::MODE_TEMPORAL,
                service(TemporalWorker::class),
            ])
        ;
    }

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
