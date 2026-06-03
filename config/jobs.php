<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\IgbinaryJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\SymfonyJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\Jobs\Consumer;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Jobs;
use Spiral\RoadRunner\Jobs\JobsInterface;
use Spiral\RoadRunner\Worker as RoadRunnerWorker;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Serializer\SerializerInterface;

return static function (ContainerConfigurator $container): void {
    if (!class_exists(Consumer::class)) {
        return;
    }

    $services = $container->services();

    $services
        ->set("fluffy_discord.jobs.rr_worker", RoadRunnerWorker::class)
        ->factory([RoadRunnerWorker::class, "createFromEnvironment"])
        ->args([
            service(EnvironmentInterface::class),
        ])
    ;

    $services
        ->set(ConsumerInterface::class, Consumer::class)
        ->args([
            service("fluffy_discord.jobs.rr_worker"),
        ])
    ;

    $services
        ->set(JobsWorker::class)
        ->public()
        ->args([
            false,
            service(KernelInterface::class),
            service(ConsumerInterface::class),
            service("fluffy_discord.jobs.rr_worker"),
            service(EventDispatcherInterface::class),
            service("services_resetter")->nullOnInvalid(),
            service(SentryHubInterface::class)->nullOnInvalid(),
        ])
    ;

    $services
        ->get(WorkerRegistry::class)
        ->call("registerWorker", [
            Environment\Mode::MODE_JOBS,
            service(JobsWorker::class),
        ])
    ;

    $services
        ->set(JobsInterface::class, Jobs::class)
        ->args([
            service(RPCInterface::class),
        ])
    ;

    $services->set(NativeJobSerializer::class);
    $services->set(IgbinaryJobSerializer::class);
    $services
        ->set(SymfonyJobSerializer::class)
        ->args([
            service(SerializerInterface::class)->nullOnInvalid(),
        ])
    ;

    $services->alias(JobSerializerInterface::class, NativeJobSerializer::class);

    $services
        ->set(JobDispatcher::class)
        ->public()
        ->args([
            service(JobsInterface::class),
            service(JobSerializerInterface::class),
            "default", // replaced by the Extension from jobs.default_queue
        ])
    ;

    $services
        ->set(JobRoutingListener::class)
        ->args([
            abstract_arg('ServiceLocator — set by JobHandlerPass'),
            abstract_arg('routing table — set by JobHandlerPass'),
            [
                'native'   => service(NativeJobSerializer::class),
                'igbinary' => service(IgbinaryJobSerializer::class),
                'symfony'  => service(SymfonyJobSerializer::class),
            ],
        ])
        ->tag('kernel.event_listener', ['event' => JobsRunEvent::class, 'method' => 'onJobsRun', 'priority' => -100])
    ;
};
