<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Command\GrpcDebugCommand;
use FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcFrameDecoder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcInvoker;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcResponseEncoder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntimeFactory;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAuthorizationGuard;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\GrpcWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

return static function (ContainerConfigurator $container): void {
    if (!interface_exists(ServiceInterface::class)) {
        return;
    }

    $services = $container->services();

    $services
        ->set(GrpcServiceRegistry::class)
        ->args([
            abstract_arg('service locator built by GrpcServicePass'),
        ])
    ;

    $services
        ->set(GrpcInvoker::class)
        ->args([
            service(EventDispatcherInterface::class),
            service(GrpcAuthorizationGuard::class)->nullOnInvalid(),
        ])
    ;

    $services->set(GrpcFrameDecoder::class);
    $services->set(GrpcResponseEncoder::class);

    $services
        ->set(RoadRunnerYamlConfigReader::class)
        ->args([
            param('kernel.project_dir'),
            param('fluffy_discord.roadrunner.rr_config_path'),
        ])
    ;

    $services
        ->set(GrpcWorkerRuntimeFactory::class)
        ->public()
        ->args([
            service(EventDispatcherInterface::class),
            service(GrpcServiceRegistry::class),
            service(GrpcInvoker::class),
            service(GrpcCallAuthenticatorInterface::class)->nullOnInvalid(),
            service('services_resetter')->nullOnInvalid(),
        ])
    ;

    $services
        ->set(GrpcWorker::class)
        ->public()
        ->args([
            service(KernelInterface::class),
            service(RrWorkerInterface::class),
            service(GrpcFrameDecoder::class),
            service(GrpcResponseEncoder::class),
            param('kernel.debug'),
            service(SentryHubInterface::class)->nullOnInvalid(),
        ])
    ;

    $services
        ->get(WorkerRegistry::class)
        ->call('registerWorker', [
            Environment\Mode::MODE_GRPC,
            service(GrpcWorker::class),
        ])
    ;

    $services
        ->set(GrpcIntrospector::class)
        ->args([
            service(GrpcServiceRegistry::class),
            service(RoadRunnerYamlConfigReader::class),
            param('fluffy_discord.roadrunner.grpc.security_enabled'),
        ])
    ;

    $services
        ->set(GrpcDebugCommand::class)
        ->autowire()
        ->autoconfigure()
    ;
};
