<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use FluffyDiscord\RoadRunnerBundle\Command\TemporalDebugCommand;
use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncher;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncherInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospectorInterface;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCConnectionFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\TemporalClientFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\ActivityInboundInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowClientCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowInboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Logging\TemporalLogProcessor;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalCredentialsFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry;
use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\WorkflowContextClearingHostConnection;
use FluffyDiscord\RoadRunnerBundle\Worker\TemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
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
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryActivityInboundInterceptor;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowClientCallsInterceptor;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowOutboundRequestInterceptor;
use Temporal\OpenTelemetry\Tracer as OpenTelemetryTracer;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Worker\Transport\RoadRunner as TemporalRoadRunner;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\WorkerFactory;
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
            service(WorkerFactoryInterface::class),
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
        ->arg('$workerOptions', param('fluffy_discord.roadrunner.temporal.worker_options'))
        ->arg('$logger', service('monolog.logger.temporal')->nullOnInvalid())
    ;

    $services
        ->set(TemporalCollector::class)
        ->autowire()
        ->tag('data_collector', [
            'id'       => 'fluffy_discord.roadrunner.temporal',
            'template' => '@FluffyDiscordRoadRunner/Collector/temporal.html.twig',
        ])
        ->tag('fluffy_discord.roadrunner.temporal.interceptor')
        ->tag('kernel.reset', ['method' => 'reset'])
    ;

    if (interface_exists(ProcessorInterface::class)) {
        $services
            ->set(TemporalLogProcessor::class)
            ->tag('monolog.processor')
        ;
    }

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
            param('fluffy_discord.roadrunner.temporal.api_key'),
        ])
    ;

    $services
        ->set(WorkerFactory::class)
        ->lazy()
        ->factory([WorkerFactory::class, 'create'])
        ->args([
            service(DataConverterInterface::class),
            service(RPCConnectionInterface::class),
            service(ServiceCredentials::class),
        ])
    ;
    $services->alias(WorkerFactoryInterface::class, WorkerFactory::class);

    $services
        ->set(TemporalRoadRunner::class)
        ->factory([TemporalRoadRunner::class, 'create'])
        ->args([
            service(EnvironmentInterface::class),
        ])
    ;
    $services->alias(HostConnectionInterface::class, TemporalRoadRunner::class);

    $services
        ->set(WorkflowContextClearingHostConnection::class)
        ->decorate(HostConnectionInterface::class)
        ->args([service('.inner')])
    ;

    $services
        ->set(ExceptionInterceptor::class)
        ->public()
        ->args([
            param('fluffy_discord.roadrunner.temporal.retryable_errors'),
        ])
    ;
    $services->alias(ExceptionInterceptorInterface::class, ExceptionInterceptor::class);

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
    // tagged into the pipeline by the Extension's registerForAutoconfiguration(Interceptor::class).
    $eventInterceptors = [
        ActivityInboundInterceptor::class       => \Temporal\Interceptor\ActivityInboundInterceptor::class,
        WorkflowClientCallsInterceptor::class   => \Temporal\Interceptor\WorkflowClientCallsInterceptor::class,
        WorkflowInboundCallsInterceptor::class  => \Temporal\Interceptor\WorkflowInboundCallsInterceptor::class,
        WorkflowOutboundCallsInterceptor::class => \Temporal\Interceptor\WorkflowOutboundCallsInterceptor::class,
    ];
    foreach ($eventInterceptors as $implementation => $sdkInterface) {
        $services
            ->set($implementation)
            ->autoconfigure()
            ->args([service(EventDispatcherInterface::class)])
        ;
        $services->alias($sdkInterface, $implementation);
    }

    $services
        ->set(ServiceClientInterface::class)
        ->factory([
            inline_service(ServiceClientInterface::class)
                ->factory([TemporalClientFactory::class, 'serviceClient'])
                ->args([
                    param('fluffy_discord.roadrunner.temporal.address'),
                    param('fluffy_discord.roadrunner.temporal.api_key'),
                ]),
            'withInterceptorPipeline',
        ])
        ->args([
            inline_service(Pipeline::class)
                ->factory([service(PipelineProvider::class), 'getPipeline'])
                ->args([GrpcClientInterceptor::class]),
        ])
    ;

    if (class_exists(OpenTelemetryTracer::class)) {
        $services
            ->set(OpenTelemetryTracer::class)
            ->args([
                inline_service(TracerInterface::class)
                    ->factory([
                        inline_service(TracerProviderInterface::class)->factory([Globals::class, 'tracerProvider']),
                        'getTracer',
                    ])
                    ->args(['temporal']),
                inline_service(TextMapPropagatorInterface::class)->factory([Globals::class, 'propagator']),
            ])
        ;

        $openTelemetryInterceptors = [
            OpenTelemetryActivityInboundInterceptor::class,
            OpenTelemetryWorkflowClientCallsInterceptor::class,
            OpenTelemetryWorkflowOutboundRequestInterceptor::class,
        ];
        foreach ($openTelemetryInterceptors as $openTelemetryInterceptor) {
            $services
                ->set($openTelemetryInterceptor)
                ->autoconfigure()
                ->args([service(OpenTelemetryTracer::class)])
            ;
        }
    }

    $services
        ->set(ClientOptions::class)
        ->factory([TemporalClientFactory::class, 'clientOptions'])
        ->args([
            param('fluffy_discord.roadrunner.temporal.namespace'),
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
    $services->alias(TemporalIntrospectorInterface::class, TemporalIntrospector::class);

    $services
        ->set(TemporalDebugCommand::class)
        ->autowire()
        ->autoconfigure()
    ;
};
