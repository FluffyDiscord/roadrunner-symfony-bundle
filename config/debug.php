<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoDataCollector;
use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoProfilerSubscriber;

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
};
