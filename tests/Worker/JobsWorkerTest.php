<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\Environment\Mode;

/**
 * @see \FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker
 * @see docs/specs/rr-jobs-worker.md §N-2
 */
#[AllowMockObjectsWithoutExpectations]
class JobsWorkerTest extends AbstractJobsWorkerTestCase
{
    public function testSuccessfulTaskIsAckedOnce(): void
    {
        $task = $this->makeTask();

        $this->rrWorker->expects($this->never())->method('error');
        $this->rrWorker->expects($this->never())->method('stop');

        $dispatched = [];
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;
                return $event;
            },
        );

        $worker = $this->makeWorker([$task]);
        $worker->start();

        self::assertSame(1, $task->ackCount);
        self::assertSame([], $task->nackCalls);

        $jobsRunEvents = array_filter($dispatched, static fn(object $e): bool => $e instanceof JobsRunEvent);
        self::assertCount(1, $jobsRunEvents);

        $responseSent = array_filter($dispatched, static fn(object $e): bool => $e instanceof WorkerResponseSentEvent);
        self::assertCount(1, $responseSent);
        /** @var WorkerResponseSentEvent $first */
        $first = array_values($responseSent)[0];
        self::assertSame(Mode::MODE_JOBS, $first->workerType);
    }

    public function testEmptyQueueDoesNothing(): void
    {
        $this->rrWorker->expects($this->never())->method('stop');
        $this->eventDispatcher->expects($this->once())->method('dispatch'); // only WorkerBootingEvent

        $worker = $this->makeWorker([]);
        $worker->start();

        self::assertSame([], $worker->loggedErrors);
    }

    public function testSoftFailureNacksWithRequeueAndDoesNotStop(): void
    {
        $task = $this->makeTask();

        $this->rrWorker->expects($this->never())->method('stop');
        $this->rrWorker->expects($this->never())->method('error');

        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event instanceof JobsRunEvent ? throw new \RuntimeException('boom soft') : $event,
        );

        $worker = $this->makeWorker([$task]);
        $worker->start();

        self::assertSame(0, $task->ackCount);
        self::assertCount(1, $task->nackCalls);
        self::assertTrue($task->nackCalls[0]['redelivery']);
        self::assertStringContainsString('boom soft', $task->nackCalls[0]['message']);

        self::assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'boom soft')),
        );
    }

    public function testHardErrorNacksAndStopsWorker(): void
    {
        $task = $this->makeTask();

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');
        $this->rrWorker->expects($this->never())->method('error');

        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event instanceof JobsRunEvent ? throw new \Error('boom hard') : $event,
        );

        $worker = $this->makeWorker([$task]);
        $worker->start();

        self::assertCount(1, $task->nackCalls);
        self::assertTrue($task->nackCalls[0]['redelivery']);

        self::assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'boom hard')),
        );
    }

    public function testJobsRunEventGettersDelegateToTask(): void
    {
        $task = $this->makeTask(name: 'send-email', payload: '{"to":"x"}', headers: ['X-Try' => ['1']]);
        $event = new JobsRunEvent($task);

        self::assertSame($task, $event->getTask());
        self::assertSame('test-queue', $event->getQueue());
        self::assertSame('test-pipeline', $event->getPipeline());
        self::assertSame('send-email', $event->getName());
        self::assertSame('task-id', $event->getId());
        self::assertSame('{"to":"x"}', $event->getPayload());
        self::assertSame(['X-Try' => ['1']], $event->getHeaders());
    }

    public function testJobsRunEventEmptyHeaders(): void
    {
        $event = new JobsRunEvent($this->makeTask());
        self::assertSame([], $event->getHeaders());
    }

    public function testListenerThatAcksItselfIsNotReacked(): void
    {
        $task = $this->makeTask(completed: true); // isCompleted() => true after listener acked

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $worker = $this->makeWorker([$task]);
        $worker->start();

        self::assertSame(0, $task->ackCount); // worker did not call ack() again
        self::assertSame([], $task->nackCalls);
        self::assertSame([], $worker->loggedErrors);
    }

    public function testListenerThatNackedThenThrowsIsNotReacked(): void
    {
        $task = $this->makeTask(completed: true);

        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event instanceof JobsRunEvent ? throw new \RuntimeException('after listener nacked') : $event,
        );

        $worker = $this->makeWorker([$task]);
        $worker->start();

        self::assertSame(0, $task->ackCount);
        self::assertSame([], $task->nackCalls); // worker must not nack again, listener owns it
        self::assertNotEmpty($worker->loggedErrors);
    }

    public function testShutdownRequeuesAndLogsFatalWhenTaskInFlight(): void
    {
        $task = $this->makeTask();

        $worker = $this->makeWorker();
        $worker->callHandleShutdown(true, false, $task, [
            'message' => 'Boom fatal',
            'file'    => '/app/Job.php',
            'line'    => 42,
        ]);

        self::assertCount(1, $task->nackCalls);
        self::assertTrue($task->nackCalls[0]['redelivery']);

        self::assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'Boom fatal')),
        );
    }

    public function testShutdownLogsGenericForBareDieExit(): void
    {
        $task = $this->makeTask();

        $worker = $this->makeWorker();
        $worker->callHandleShutdown(true, false, $task, null);

        self::assertCount(1, $task->nackCalls);
        self::assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'die/exit')),
        );
    }

    public function testShutdownNoopWhenAlreadyResponded(): void
    {
        $task = $this->makeTask();

        $worker = $this->makeWorker();
        $worker->callHandleShutdown(true, true, $task, null);

        self::assertSame([], $task->nackCalls);
        self::assertSame([], $worker->loggedErrors);
    }

    public function testShutdownNoopWhenNotHandlingTask(): void
    {
        $worker = $this->makeWorker();
        $worker->callHandleShutdown(false, false, $this->makeTask(), null);
        self::assertSame([], $worker->loggedErrors);
    }

    public function testShutdownNoopWhenNoTask(): void
    {
        $worker = $this->makeWorker();
        $worker->callHandleShutdown(true, false, null, null);
        self::assertSame([], $worker->loggedErrors);
    }

    public function testShutdownDoesNotNackAlreadyCompletedTask(): void
    {
        $task = $this->makeTask(completed: true);

        $worker = $this->makeWorker();
        $worker->callHandleShutdown(true, false, $task, null);

        self::assertSame([], $task->nackCalls);
        self::assertNotEmpty($worker->loggedErrors); // still logs
    }

    public function testShutdownSwallowsNackThrow(): void
    {
        $task = $this->makeTask();
        $task->nackThrows = new \RuntimeException('relay dead');

        $worker = $this->makeWorker();
        $worker->callHandleShutdown(true, false, $task, null); // must not throw

        self::assertNotEmpty($worker->loggedErrors);
    }

    public function testShutdownRegisteredOncePerInstance(): void
    {
        $worker = $this->makeWorker(); // empty queue => waitTask() null => loop skipped
        $worker->start();
        $worker->start();

        self::assertSame(1, $worker->shutdownRegistrations);
        self::assertInstanceOf(\Closure::class, $worker->registeredShutdown);
    }

    public function testManyTasksAllAckedCleanly(): void
    {
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $tasks = [];
        for ($i = 0; $i < 25; ++$i) {
            $tasks[] = $this->makeTask();
        }

        $worker = $this->makeWorker($tasks);
        $worker->start();

        foreach ($tasks as $task) {
            self::assertSame(1, $task->ackCount);
        }
        self::assertSame([], $worker->loggedErrors);
    }

    public function testSendThrowableResponseFallsBackToErrorWhenNackThrows(): void
    {
        $task = $this->makeTask();
        $task->nackThrows = new \RuntimeException('relay corrupt');

        $this->rrWorker->expects($this->once())->method('error');

        $worker = $this->makeWorker();
        $worker->callSendThrowableResponse($task, new \RuntimeException('original'));
    }
}
