<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

/**
 * Loop-level integration tests (mock-driven). See docs/specs/rr-jobs-worker.md §N-2 IT-01..IT-03.
 */
#[AllowMockObjectsWithoutExpectations]
class JobsWorkerIntegrationTest extends AbstractJobsWorkerTestCase
{
    public function testMultiTaskLoopAnswersEachExactlyOnce(): void
    {
        $ok1 = $this->makeTask(name: 'ok-1');
        $bad = $this->makeTask(name: 'bad');
        $ok2 = $this->makeTask(name: 'ok-2');

        $seenNames = [];
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            function (object $event) use (&$seenNames): object {
                if ($event instanceof JobsRunEvent) {
                    $seenNames[] = $event->getName();
                    if ($event->getName() === 'bad') {
                        throw new \RuntimeException('this one fails');
                    }
                }
                return $event;
            },
        );

        $worker = $this->makeWorker([$ok1, $bad, $ok2]);
        $worker->start();

        self::assertSame(['ok-1', 'bad', 'ok-2'], $seenNames);

        self::assertSame(1, $ok1->ackCount);
        self::assertSame([], $ok1->nackCalls);

        self::assertSame(0, $bad->ackCount);
        self::assertCount(1, $bad->nackCalls);
        self::assertTrue($bad->nackCalls[0]['redelivery']);

        self::assertSame(1, $ok2->ackCount);
    }

    public function testFailureTriggersResetAndReboot(): void
    {
        // A kernel mock that is also RebootableInterface so $kernel instanceof RebootableInterface holds.
        $kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, RebootableInterface::class]);
        $kernel->expects($this->once())->method('reboot')->with(null);

        $this->servicesResetter->expects($this->atLeastOnce())->method('reset');

        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event instanceof JobsRunEvent ? throw new \RuntimeException('boom') : $event,
        );

        $task = $this->makeTask();

        $worker = new TestableJobsWorker(
            lazyBoot: true,
            kernel: $kernel,
            consumer: $this->consumer,
            rrWorker: $this->rrWorker,
            eventDispatcher: $this->eventDispatcher,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: null,
        );
        $worker->taskQueue = [$task];
        $worker->start();

        self::assertCount(1, $task->nackCalls);
        self::assertNotEmpty($worker->loggedErrors);
    }

    public function testSuccessResetsServicesWithoutReboot(): void
    {
        $kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, RebootableInterface::class]);
        $kernel->expects($this->never())->method('reboot');

        $this->servicesResetter->expects($this->once())->method('reset');

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $task = $this->makeTask();

        $worker = new TestableJobsWorker(
            lazyBoot: true,
            kernel: $kernel,
            consumer: $this->consumer,
            rrWorker: $this->rrWorker,
            eventDispatcher: $this->eventDispatcher,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: null,
        );
        $worker->taskQueue = [$task];
        $worker->start();

        self::assertSame(1, $task->ackCount);
        self::assertSame([], $worker->loggedErrors);
    }

    public function testWorkerRegistersUnderJobsMode(): void
    {
        $registry = new WorkerRegistry();
        /** @var WorkerInterface $worker */
        $worker = $this->makeWorker();

        $registry->registerWorker(Mode::MODE_JOBS, $worker);

        self::assertSame($worker, $registry->getWorker(Mode::MODE_JOBS));
        self::assertSame('jobs', Mode::MODE_JOBS);
    }

    public function testConsumerSeamIsUsedWhenNotOverridden(): void
    {
        // Verify the default waitTask() delegates to the injected ConsumerInterface.
        $task = $this->makeTask();
        $this->consumer->expects($this->atLeastOnce())->method('waitTask')->willReturnOnConsecutiveCalls($task, null);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        // Use the real (non-Testable) JobsWorker so waitTask() hits the consumer.
        $worker = new JobsWorker(
            lazyBoot: true,
            kernel: $this->kernel,
            consumer: $this->consumer,
            rrWorker: $this->rrWorker,
            eventDispatcher: $this->eventDispatcher,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: null,
        );

        $worker->start();

        // Reaching here without error means the consumer-backed loop ran and terminated on null.
        $this->addToAssertionCount(1);
    }
}
