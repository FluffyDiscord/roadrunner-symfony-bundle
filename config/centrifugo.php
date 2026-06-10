<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Command\CentrifugoDebugCommand;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\EventListener\CentrifugoEventRouter;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\CentrifugoWorkerInterface;
use RoadRunner\Centrifugo\Request\RequestFactory;
use RoadRunner\Centrifugo\RPCCentrifugoApi;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\WorkerInterface as RoadRunnerWorkerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

return static function (ContainerConfigurator $container): void {
    if (!class_exists(RoadRunnerCentrifugoWorker::class)) {
        return;
    }

    $services = $container->services();

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
            param('kernel.debug'),
            service(KernelInterface::class),
            service(CentrifugoWorkerInterface::class),
            service(EventDispatcherInterface::class),
            service("services_resetter")->nullOnInvalid(),
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

    $services
        ->set(CentrifugoEventRouter::class)
        ->args([
            abstract_arg('ServiceLocator — set by CentrifugoRouterPass'),
            abstract_arg('routing table — set by CentrifugoRouterPass'),
        ])
        ->tag('kernel.event_listener', ['event' => ConnectEvent::class,    'method' => 'onConnect',    'priority' => -100])
        ->tag('kernel.event_listener', ['event' => PublishEvent::class,    'method' => 'onPublish',    'priority' => -100])
        ->tag('kernel.event_listener', ['event' => SubscribeEvent::class,  'method' => 'onSubscribe',  'priority' => -100])
        ->tag('kernel.event_listener', ['event' => SubRefreshEvent::class, 'method' => 'onSubRefresh', 'priority' => -100])
        ->tag('kernel.event_listener', ['event' => RPCEvent::class,        'method' => 'onRpc',        'priority' => -100])
    ;

    $services
        ->set(CentrifugoDebugCommand::class)
        ->autowire()
        ->autoconfigure()
    ;
};
