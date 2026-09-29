<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\TemporalWorkerPass;
use FluffyDiscord\RoadRunnerBundle\Exception\ActivityNotAssignedException;
use FluffyDiscord\RoadRunnerBundle\Exception\UnknownTaskQueueException;
use FluffyDiscord\RoadRunnerBundle\Exception\WorkflowNotAssignedException;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingWorkflow;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\UnassignedActivity;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * TC-03 — the compile-time scan that tags workflows/activities and records
 * addWorkflow/addActivity calls on the initializer.
 */
class ExtensionTemporalPassTest extends BaseTestCase
{
    private function containerWithInitializer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(TemporalWorkerInitializer::class, new Definition(TemporalWorkerInitializer::class));

        return $container;
    }

    public function testWorkflowAndActivityAreTaggedAndRecorded(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition(GreetingActivity::class, new Definition(GreetingActivity::class));
        $container->setDefinition(GreetingWorkflow::class, new Definition(GreetingWorkflow::class));

        (new TemporalWorkerPass())->process($container);

        $activity = $container->getDefinition(GreetingActivity::class);
        self::assertTrue($activity->hasTag('fluffy_discord.roadrunner.temporal.activity'));
        self::assertFalse($activity->isShared());
        self::assertTrue($activity->isPublic());

        $workflow = $container->getDefinition(GreetingWorkflow::class);
        self::assertTrue($workflow->hasTag('fluffy_discord.roadrunner.temporal.workflow'));

        $calls = $container->getDefinition(TemporalWorkerInitializer::class)->getMethodCalls();
        $methods = array_map(static fn (array $call) => $call[0], $calls);
        self::assertContains('addActivity', $methods);
        self::assertContains('addWorkflow', $methods);

        foreach ($calls as [$method, $args]) {
            if ($method === 'addActivity') {
                self::assertSame(GreetingActivity::class, $args[0]);
                self::assertSame(['default'], $args[1]);
            }
            if ($method === 'addWorkflow') {
                self::assertSame(GreetingWorkflow::class, $args[0]);
                self::assertSame(['default'], $args[1]);
            }
        }
    }

    public function testWorkerOptionsForDeclaredQueuesAreAccepted(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition(BillingWorkflowForTest::class, new Definition(BillingWorkflowForTest::class));
        $container->setParameter('fluffy_discord.roadrunner.temporal.worker_options', [
            'default' => ['maxConcurrentActivityExecutionSize' => 10],
            'billing' => ['maxConcurrentActivityExecutionSize' => 4],
        ]);

        (new TemporalWorkerPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    public function testWorkerOptionsForUndeclaredQueueFailTheBuild(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition(BillingWorkflowForTest::class, new Definition(BillingWorkflowForTest::class));
        $container->setParameter('fluffy_discord.roadrunner.temporal.worker_options', [
            'biling' => ['maxConcurrentActivityExecutionSize' => 4],
        ]);

        $this->expectException(UnknownTaskQueueException::class);
        $this->expectExceptionMessage('"biling"');

        (new TemporalWorkerPass())->process($container);
    }

    public function testCollectorIsRemovedWithoutProfiler(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition(TemporalCollector::class, new Definition(TemporalCollector::class));

        (new TemporalWorkerPass())->process($container);

        self::assertFalse($container->hasDefinition(TemporalCollector::class));
    }

    public function testCollectorIsKeptWithProfiler(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition('profiler', new Definition(\stdClass::class));
        $container->setDefinition(TemporalCollector::class, new Definition(TemporalCollector::class));

        (new TemporalWorkerPass())->process($container);

        self::assertTrue($container->hasDefinition(TemporalCollector::class));
    }

    public function testActivityWithoutAssignmentThrows(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition(UnassignedActivity::class, new Definition(UnassignedActivity::class));

        $this->expectException(ActivityNotAssignedException::class);

        (new TemporalWorkerPass())->process($container);
    }

    public function testWorkflowWithoutAssignmentThrows(): void
    {
        $container = $this->containerWithInitializer();
        $container->setDefinition(OrphanWorkflowForTest::class, new Definition(OrphanWorkflowForTest::class));

        $this->expectException(WorkflowNotAssignedException::class);

        (new TemporalWorkerPass())->process($container);
    }
}

#[\Temporal\Workflow\WorkflowInterface]
class OrphanWorkflowForTest
{
    #[\Temporal\Workflow\WorkflowMethod]
    public function run(): \Generator
    {
        yield;
    }
}

#[\Temporal\Workflow\WorkflowInterface]
#[\FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue('billing')]
class BillingWorkflowForTest
{
    #[\Temporal\Workflow\WorkflowMethod]
    public function run(): \Generator
    {
        yield;
    }
}
