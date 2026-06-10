<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingWorkflow;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Worker\WorkerOptions;

/**
 * TC-15 — the profiler data collector.
 */
#[AllowMockObjectsWithoutExpectations]
class TemporalCollectorTest extends BaseTestCase
{
    private function collector(TemporalWorkerInitializer $initializer): TemporalCollector
    {
        return new TemporalCollector(new TemporalIntrospector($initializer));
    }

    private function initializerWith(callable $configure): TemporalWorkerInitializer
    {
        $bundleWorker = new class implements TemporalWorkerInterface {
            public function getTaskQueue(): string
            {
                return 'default';
            }

            public function getWorkerOptions(): WorkerOptions
            {
                return WorkerOptions::new();
            }
        };

        $initializer = new TemporalWorkerInitializer(
            $this->createMock(KernelInterface::class),
            $this->createMock(ServicesResetterInterface::class),
            [$bundleWorker],
            new ExceptionInterceptor([\Error::class]),
            new SimplePipelineProvider([]),
            null,
        );

        $configure($initializer);

        return $initializer;
    }

    public function testCollectsWorkersWorkflowsAndActivities(): void
    {
        $initializer = $this->initializerWith(static function (TemporalWorkerInitializer $i): void {
            $i->addWorkflow(GreetingWorkflow::class, ['default']);
            $i->addActivity(GreetingActivity::class, ['default']);
        });

        $collector = $this->collector($initializer);
        $collector->collect(new Request(), new Response());

        $workers = $collector->getWorkers();
        self::assertCount(1, $workers);
        self::assertSame('default', $workers[0]['id']);

        $workflows = $collector->getWorkflows();
        self::assertArrayHasKey(GreetingWorkflow::class, $workflows);
        self::assertSame(['default'], $workflows[GreetingWorkflow::class]['taskQueues']);
        // Type id resolved from attributes via the SDK reader — no RPC, no live worker.
        self::assertSame(['GreetingWorkflow'], $workflows[GreetingWorkflow::class]['ids']);

        $activities = $collector->getActivities();
        self::assertArrayHasKey(GreetingActivity::class, $activities);
        self::assertSame(['default'], $activities[GreetingActivity::class]['taskQueues']);
        self::assertSame(['greeting.greet'], $activities[GreetingActivity::class]['ids']);
    }

    public function testEmptyWhenNothingRegistered(): void
    {
        $collector = $this->collector($this->initializerWith(static fn () => null));
        $collector->collect(new Request(), new Response());

        self::assertCount(1, $collector->getWorkers());
        self::assertSame([], $collector->getWorkflows());
        self::assertSame([], $collector->getActivities());
    }

    public function testGettersAreSafeBeforeCollect(): void
    {
        $collector = $this->collector($this->initializerWith(static fn () => null));

        self::assertSame([], $collector->getWorkers());
        self::assertSame([], $collector->getWorkflows());
        self::assertSame([], $collector->getActivities());
    }
}
