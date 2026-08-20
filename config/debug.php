<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoDataCollector;
use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoProfilerSubscriber;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcDataCollector;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcProfilerSubscriber;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Debug-only services.
 * Loaded by FluffyDiscordRoadRunnerExtension only when kernel.debug = true.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(CentrifugoDataCollector::class)
        ->tag('data_collector', [
            'template' => '@FluffyDiscordRoadRunner/Collector/centrifugo.html.twig',
            'id'       => 'centrifugo',
            'priority' => 255,
        ])
    ;

    $services
        ->set(CentrifugoProfilerSubscriber::class)
        ->args([
            service(CentrifugoDataCollector::class),
            service('profiler')->nullOnInvalid(),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('kernel.reset', ['method' => 'reset'])
    ;

    if (!interface_exists(ServiceInterface::class)) {
        return;
    }

    $services
        ->set(GrpcDataCollector::class)
        ->tag('data_collector', [
            'template' => '@FluffyDiscordRoadRunner/Collector/grpc.html.twig',
            'id'       => 'grpc',
            'priority' => 255,
        ])
    ;

    $services
        ->set(GrpcProfilerSubscriber::class)
        ->args([
            service(GrpcDataCollector::class),
            service('profiler')->nullOnInvalid(),
            service('.virtual_request_stack')->nullOnInvalid(),
            service('debug.stopwatch')->nullOnInvalid(),
            service(TokenStorageInterface::class)->nullOnInvalid(),
            service(EnvironmentInterface::class),
            param('fluffy_discord.roadrunner.grpc.redacted_metadata_keys'),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
};
