<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerRequestLoopTest extends AbstractHttpWorkerTestCase
{
    public function testNullRequestBreaksLoop(): void
    {
        $this->psr7Worker->expects($this->once())->method('waitRequest')->willReturn(null);

        $this->makeWorker()->start();

        $this->addToAssertionCount(1);
    }

    public function testWaitRequestExceptionResponds418AndContinues(): void
    {
        $callCount = 0;
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('transport error');
                }
                return null;
            })
        ;

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(fn($r) => $r->getStatusCode() === Response::HTTP_I_AM_A_TEAPOT))
        ;

        $this->makeWorker()->start();
    }

    public function testWaitRequestExceptionDoesNotDispatchRequestOrResponseEvents(): void
    {
        $callCount = 0;
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnCallback(function () use (&$callCount) {
                return ++$callCount === 1 ? throw new \RuntimeException() : null;
            })
        ;

        $dispatched = [];
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatched) {
                $dispatched[] = $e;
                return $e;
            })
        ;

        $this->makeWorker()->start();

        $this->assertEmpty(
            array_filter($dispatched, static fn($e) => $e instanceof WorkerRequestReceivedEvent),
        );
        $this->assertEmpty(
            array_filter($dispatched, static fn($e) => $e instanceof WorkerResponseSentEvent),
        );
    }
}
