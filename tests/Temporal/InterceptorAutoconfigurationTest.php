<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\ActivityInboundInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowClientCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowInboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\AppClientInterceptor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Interceptor\PipelineProvider;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryActivityInboundInterceptor;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowClientCallsInterceptor;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowOutboundRequestInterceptor;
use Temporal\OpenTelemetry\Tracer;

class InterceptorAutoconfigurationTest extends BaseTestCase
{
    private const string INTERCEPTOR_TAG = 'fluffy_discord.roadrunner.temporal.interceptor';

    private function loadWithAppInterceptor(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');

        (new FluffyDiscordRoadRunnerExtension())->load(
            [[
                'rr_config_path' => 'temporal.rr.yaml',
                'kv'             => ['auto_register' => false],
                'temporal'       => [],
            ]],
            $container,
        );

        $appInterceptor = new Definition(AppClientInterceptor::class);
        $appInterceptor->setAutoconfigured(true);
        $container->setDefinition(AppClientInterceptor::class, $appInterceptor);

        (new ResolveClassPass())->process($container);
        (new ResolveInstanceofConditionalsPass())->process($container);

        return $container;
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function interceptorServices(): iterable
    {
        yield 'app interceptor' => [AppClientInterceptor::class];
        yield 'bundle activity inbound' => [ActivityInboundInterceptor::class];
        yield 'bundle workflow client' => [WorkflowClientCallsInterceptor::class];
        yield 'bundle workflow inbound' => [WorkflowInboundCallsInterceptor::class];
        yield 'bundle workflow outbound' => [WorkflowOutboundCallsInterceptor::class];
        yield 'opentelemetry activity inbound' => [OpenTelemetryActivityInboundInterceptor::class];
        yield 'opentelemetry workflow client' => [OpenTelemetryWorkflowClientCallsInterceptor::class];
        yield 'opentelemetry workflow outbound' => [OpenTelemetryWorkflowOutboundRequestInterceptor::class];
    }

    /**
     * @param class-string $serviceId
     */
    #[DataProvider('interceptorServices')]
    public function testInterceptorJoinsThePipeline(string $serviceId): void
    {
        $container = $this->loadWithAppInterceptor();

        $isTagged = $container->getDefinition($serviceId)->hasTag(self::INTERCEPTOR_TAG);

        self::assertTrue($isTagged);
    }

    public function testServiceClientRunsGrpcInterceptorsFromThePipeline(): void
    {
        $container = $this->loadWithAppInterceptor();
        $serviceClient = $container->getDefinition(ServiceClientInterface::class);

        $pipeline = $serviceClient->getArgument(0);

        self::assertSame('withInterceptorPipeline', $serviceClient->getFactory()[1]);
        self::assertInstanceOf(Definition::class, $pipeline);
        self::assertSame(PipelineProvider::class, (string) $pipeline->getFactory()[0]);
        self::assertSame([GrpcClientInterceptor::class], $pipeline->getArguments());
    }

    public function testOpenTelemetryInterceptorLeavesThePipelineWhenNotAutoconfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');

        (new FluffyDiscordRoadRunnerExtension())->load(
            [[
                'rr_config_path' => 'temporal.rr.yaml',
                'kv'             => ['auto_register' => false],
                'temporal'       => [],
            ]],
            $container,
        );
        $container->getDefinition(OpenTelemetryWorkflowOutboundRequestInterceptor::class)->setAutoconfigured(false);

        (new ResolveClassPass())->process($container);
        (new ResolveInstanceofConditionalsPass())->process($container);

        $isTagged = $container->getDefinition(OpenTelemetryWorkflowOutboundRequestInterceptor::class)->hasTag(self::INTERCEPTOR_TAG);

        self::assertFalse($isTagged);
    }

    public function testOpenTelemetryInterceptorsShareTheBundledTracer(): void
    {
        $container = $this->loadWithAppInterceptor();

        $tracerArgument = $container->getDefinition(OpenTelemetryWorkflowClientCallsInterceptor::class)->getArgument(0);

        self::assertTrue($container->hasDefinition(Tracer::class));
        self::assertInstanceOf(Reference::class, $tracerArgument);
        self::assertSame(Tracer::class, (string) $tracerArgument);
    }
}
