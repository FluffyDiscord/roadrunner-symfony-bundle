<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\EventListener\DumpCaptureListener;
use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\VarDumper\VarDumper;

return static function (ContainerConfigurator $container) {
    if (!class_exists(VarDumper::class)) {
        return;
    }

    $services = $container->services();

    $services
        ->set(DumpCapture::class)
        ->args([
            param('kernel.debug'),
            param('kernel.project_dir'),
            service(FileLinkFormatter::class)->nullOnInvalid(),
            null, // filled by DumpDestinationPass from var_dumper.server_connection
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;

    $services
        ->set(DumpCaptureListener::class)
        ->args([
            service(DumpCapture::class),
        ])
        ->tag('kernel.event_listener', ['event' => WorkerRequestReceivedEvent::class, 'method' => '__invoke'])
        ->tag('kernel.event_listener', ['event' => WorkerBootingEvent::class, 'method' => '__invoke'])
    ;
};
