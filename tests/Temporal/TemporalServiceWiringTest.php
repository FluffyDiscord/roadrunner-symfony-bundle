<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowInboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\TemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Interceptor\PipelineProvider;

/**
 * TC-01 / TC-17 — Temporal services are wired and registered under MODE_TEMPORAL,
 * and the SDK interceptor aliases point to the correct bundle interceptor
 * (regression guard for the copy-paste alias bug in the original branch).
 */
class TemporalServiceWiringTest extends BaseTestCase
{
    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        return $container;
    }

    public function testTemporalWorkerIsRegisteredUnderTemporalMode(): void
    {
        $container = $this->loadServices();

        self::assertTrue($container->hasDefinition(TemporalWorker::class));

        $calls = $container->getDefinition(WorkerRegistry::class)->getMethodCalls();
        $registeredModes = [];
        foreach ($calls as [$method, $args]) {
            if ($method === 'registerWorker') {
                $registeredModes[] = $args[0];
            }
        }

        self::assertContains(Mode::MODE_TEMPORAL, $registeredModes);
    }

    public function testInboundInterceptorAliasPointsToInboundImplementation(): void
    {
        $container = $this->loadServices();

        $alias = $container->getAlias(\Temporal\Interceptor\WorkflowInboundCallsInterceptor::class);

        self::assertSame(WorkflowInboundCallsInterceptor::class, (string) $alias);
    }

    public function testOutboundInterceptorAliasPointsToOutboundImplementation(): void
    {
        $container = $this->loadServices();

        $alias = $container->getAlias(\Temporal\Interceptor\WorkflowOutboundCallsInterceptor::class);

        self::assertSame(WorkflowOutboundCallsInterceptor::class, (string) $alias);
    }

    public function testWorkerRegistryIsRegisteredAndPublic(): void
    {
        $container = $this->loadServices();

        self::assertTrue($container->hasDefinition(\FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry::class));
        self::assertTrue($container->getDefinition(\FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry::class)->isPublic());
    }

    public function testClientInterfacesAreAutowirableAndPublic(): void
    {
        $container = $this->loadServices();

        self::assertTrue($container->hasAlias(WorkflowClientInterface::class));
        self::assertTrue($container->getAlias(WorkflowClientInterface::class)->isPublic());
        self::assertTrue($container->hasAlias(ScheduleClientInterface::class));
        self::assertTrue($container->getAlias(ScheduleClientInterface::class)->isPublic());
    }

    public function testWorkflowClientReusesBundleConverterAndInterceptorPipeline(): void
    {
        $container = $this->loadServices();

        // arg 2 = DataConverter, arg 3 = interceptor PipelineProvider — this is what makes
        // the client-side interceptor (and its events) fire and keeps payloads aligned with
        // the worker. Regression guard for the "hand-built client" drift.
        $definition = $container->getDefinition(WorkflowClient::class);

        self::assertSame(DataConverterInterface::class, (string) $definition->getArgument(2));
        self::assertSame(PipelineProvider::class, (string) $definition->getArgument(3));
    }
}
