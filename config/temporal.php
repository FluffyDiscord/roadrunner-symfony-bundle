<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Command\TemporalDebugCommand;
use FluffyDiscord\RoadRunnerBundle\Command\TemporalDiagramCommand;
use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncher;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncherInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCConnectionFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\TemporalClientFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorkerFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\ActivityInboundInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowClientCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowInboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalCredentialsFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry;
use FluffyDiscord\RoadRunnerBundle\Worker\TemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\EnvironmentInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\ScheduleClient;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Internal\Interceptor\Interceptor;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Worker\Transport\RoadRunner as TemporalRoadRunner;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Workflow\WorkflowInterface;

return static function (ContainerConfigurator $container): void {
    if (!class_exists(WorkflowInterface::class)) {
        return;
    }

    $services = $container->services();

    $services
        ->set(TemporalWorkerRegistry::class)
        ->public()
    ;

    $services
        ->set(TemporalWorker::class)
        ->public()
        ->args([
            service(KernelInterface::class),
            service(EventDispatcherInterface::class),
            service(TemporalWorkerFactoryInterface::class),
            service(TemporalWorkerInitializer::class),
            service(TemporalWorkerRegistry::class),
            service(HostConnectionInterface::class),
            service(SentryHubInterface::class)->nullOnInvalid(),
        ])
    ;

    $services
        ->get(WorkerRegistry::class)
        ->call("registerWorker", [
            Environment\Mode::MODE_TEMPORAL,
            service(TemporalWorker::class),
        ])
    ;

    $services
        ->set(TemporalWorkerInitializer::class)
        ->public()
        ->autowire()
        ->autoconfigure()
        ->arg('$logger', service('monolog.logger.temporal')->nullOnInvalid())
    ;

    $services
        ->set(TemporalCollector::class)
        ->autowire()
        ->tag('data_collector', [
            'id'       => 'fluffy_discord.roadrunner.temporal',
            'template' => '@FluffyDiscordRoadRunner/Collector/temporal.html.twig',
        ])
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
        ->set(DataConverter::class)
        ->factory([DataConverter::class, 'createDefault'])
    ;
    $services->alias(DataConverterInterface::class, DataConverter::class);

    $services
        ->set(ServiceCredentials::class)
        ->factory([TemporalCredentialsFactory::class, 'create'])
        ->args([
            null,
        ])
    ;

    $services
        ->set(DefaultTemporalWorkerFactory::class)
        ->public()
        ->args([
            service(RPCConnectionInterface::class),
            service(DataConverterInterface::class),
            service(ServiceCredentials::class),
        ])
    ;
    $services->alias(TemporalWorkerFactoryInterface::class, DefaultTemporalWorkerFactory::class);

    $services
        ->set(TemporalRoadRunner::class)
        ->factory([TemporalRoadRunner::class, 'create'])
        ->args([
            service(EnvironmentInterface::class),
        ])
    ;
    $services->alias(HostConnectionInterface::class, TemporalRoadRunner::class);

    $services
        ->set(ExceptionInterceptor::class)
        ->public()
        ->args([
            [
                \Error::class,
            ],
        ])
    ;
    $services->alias(ExceptionInterceptorInterface::class, ExceptionInterceptor::class);

    $services
        ->instanceof(Interceptor::class)
        ->tag('fluffy_discord.roadrunner.temporal.interceptor')
    ;

    // SimplePipelineProvider::getPipeline() runs array_filter() over the interceptors, so they
    // must be a real array — a lazy tagged_iterator would throw a TypeError.
    $services
        ->set('fluffy_discord.roadrunner.temporal.interceptors', 'array')
        ->factory('iterator_to_array')
        ->args([
            tagged_iterator('fluffy_discord.roadrunner.temporal.interceptor'),
            false,
        ])
    ;

    $services
        ->set(SimplePipelineProvider::class)
        ->args([
            service('fluffy_discord.roadrunner.temporal.interceptors'),
        ])
    ;
    $services->alias(PipelineProvider::class, SimplePipelineProvider::class);

    // Each bundle interceptor wraps a Temporal SDK interceptor call in a Symfony event; alias
    // the SDK interface to our implementation so the worker factory picks ours up. They are
    // auto-tagged into the pipeline by the instanceof(Interceptor::class) rule above.
    $eventInterceptors = [
        ActivityInboundInterceptor::class       => \Temporal\Interceptor\ActivityInboundInterceptor::class,
        WorkflowClientCallsInterceptor::class   => \Temporal\Interceptor\WorkflowClientCallsInterceptor::class,
        WorkflowInboundCallsInterceptor::class  => \Temporal\Interceptor\WorkflowInboundCallsInterceptor::class,
        WorkflowOutboundCallsInterceptor::class => \Temporal\Interceptor\WorkflowOutboundCallsInterceptor::class,
    ];
    foreach ($eventInterceptors as $implementation => $sdkInterface) {
        $services
            ->set($implementation)
            ->args([service(EventDispatcherInterface::class)])
        ;
        $services->alias($sdkInterface, $implementation);
    }

    $services
        ->set(DefaultTemporalWorker::class)
        ->public()
        ->args([
            WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
            [],
        ])
    ;
    $services->alias(TemporalWorkerInterface::class, DefaultTemporalWorker::class);

    $services
        ->set(ServiceClientInterface::class)
        ->factory([TemporalClientFactory::class, 'serviceClient'])
        ->args([
            '',
            null,
        ])
    ;

    $services
        ->set(ClientOptions::class)
        ->factory([TemporalClientFactory::class, 'clientOptions'])
        ->args([
            'default',
        ])
    ;

    $services
        ->set(WorkflowClient::class)
        ->factory([WorkflowClient::class, 'create'])
        ->args([
            service(ServiceClientInterface::class),
            service(ClientOptions::class),
            service(DataConverterInterface::class),
            service(PipelineProvider::class),
        ])
    ;
    $services->alias(WorkflowClientInterface::class, WorkflowClient::class)->public();

    $services
        ->set(ScheduleClient::class)
        ->factory([ScheduleClient::class, 'create'])
        ->args([
            service(ServiceClientInterface::class),
            service(ClientOptions::class),
            service(DataConverterInterface::class),
        ])
    ;
    $services->alias(ScheduleClientInterface::class, ScheduleClient::class)->public();

    $services
        ->set(WorkflowLauncher::class)
        ->autowire()
    ;
    $services->alias(WorkflowLauncherInterface::class, WorkflowLauncher::class);

    $services
        ->set(TemporalIntrospector::class)
        ->autowire()
    ;

    $services
        ->set(TemporalDebugCommand::class)
        ->autowire()
        ->autoconfigure()
    ;

    $services
        ->set(TemporalDiagramCommand::class)
        ->autowire()
        ->autoconfigure()
    ;
};
