<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Exception\DuplicateTemporalWorkerException;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingWorkflow;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

/**
 * TC-04 — the initializer creates a worker per task queue and registers the workflows
 * and activities assigned to that queue.
 */
#[AllowMockObjectsWithoutExpectations]
class TemporalWorkerInitializerTest extends BaseTestCase
{
    private function realWorkerFactory(): WorkerFactory
    {
        return WorkerFactory::create(
            DataConverter::createDefault(),
            $this->createMock(RPCConnectionInterface::class),
        );
    }

    private function defaultQueueWorker(): TemporalWorkerInterface
    {
        return new class implements TemporalWorkerInterface {
            public function getTaskQueue(): string
            {
                return 'default';
            }

            public function getWorkerOptions(): WorkerOptions
            {
                return WorkerOptions::new();
            }
        };
    }

    /**
     * @param iterable<TemporalWorkerInterface> $workers
     */
    private function initializer(iterable $workers): TemporalWorkerInitializer
    {
        return new TemporalWorkerInitializer(
            $this->createMock(KernelInterface::class),
            $this->createMock(ServicesResetterInterface::class),
            $workers,
            new ExceptionInterceptor([\Error::class]),
            new SimplePipelineProvider([]),
            null,
        );
    }

    public function testRegistersWorkflowAndActivityForMatchingQueue(): void
    {
        $factory = $this->realWorkerFactory();
        $config = $this->defaultQueueWorker();

        $initializer = $this->initializer([$config]);
        $initializer->addWorkflow(GreetingWorkflow::class, ['default']);
        $initializer->addActivity(GreetingActivity::class, ['default']);

        $result = $initializer->initialize($factory);

        self::assertCount(1, $result);
        self::assertSame($config, $result[0]['config']);
        self::assertSame('default', $result[0]['taskQueue']);
        $worker = $result[0]['worker'];

        $workflowClasses = array_map(
            static fn ($p) => $p->getClass()->getName(),
            iterator_to_array($worker->getWorkflows()),
        );
        self::assertContains(GreetingWorkflow::class, $workflowClasses);

        $activityClasses = array_map(
            static fn ($p) => $p->getClass()->getName(),
            iterator_to_array($worker->getActivities()),
        );
        self::assertContains(GreetingActivity::class, $activityClasses);
    }

    public function testWorkflowsForOtherQueuesAreNotRegistered(): void
    {
        $factory = $this->realWorkerFactory();

        $initializer = $this->initializer([$this->defaultQueueWorker()]);
        // Assigned to a different queue — must NOT land on the default worker.
        $initializer->addWorkflow(GreetingWorkflow::class, ['other-queue']);

        $result = $initializer->initialize($factory);

        self::assertCount(0, iterator_to_array($result[0]['worker']->getWorkflows()));
    }

    public function testTwoCustomWorkersForOneQueueThrow(): void
    {
        // Two custom (non-DefaultTemporalWorker) workers for the same queue is the real
        // misconfiguration — a task queue maps to exactly one worker.
        $this->expectException(DuplicateTemporalWorkerException::class);
        $this->expectExceptionMessage('Task queue "default" is served by more than one worker');

        $this->initializer([$this->defaultQueueWorker(), $this->defaultQueueWorker()])
            ->initialize($this->realWorkerFactory());
    }

    public function testCustomWorkerOverridesBundleDefault(): void
    {
        $custom = $this->defaultQueueWorker();

        // Order-independent: a custom worker always wins over the bundle-provided
        // DefaultTemporalWorker for the same queue, and it is not a conflict.
        foreach ([[new DefaultTemporalWorker('default'), $custom], [$custom, new DefaultTemporalWorker('default')]] as $workers) {
            $result = $this->initializer($workers)->initialize($this->realWorkerFactory());

            self::assertCount(1, $result);
            self::assertSame($custom, $result[0]['config']);
        }
    }
}
