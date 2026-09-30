<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\Goridge\Exception\HeaderException;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerRequestLoopTest extends AbstractHttpWorkerTestCase
{
    public function testNullRequestBreaksLoop(): void
    {
        $this->spiralHttpWorker->expects($this->once())->method('waitRequest')->willReturn(null);

        $this->makeWorker()->start();

        $this->addToAssertionCount(1);
    }

    public function testWaitRequestExceptionResponds418AndContinues(): void
    {
        $callCount = 0;
        $this->spiralHttpWorker
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

    public function testRelayFailureEndsTheLoopWithoutAnsweringTheDeadRelay(): void
    {
        $callCount = 0;
        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnCallback(function () use (&$callCount) {
                return ++$callCount === 1 ? throw new HeaderException('Unable to read frame header: Incorrect header size') : null;
            })
        ;

        $this->psr7Worker->expects($this->never())->method('respond');

        try {
            $this->makeWorker()->start();
            self::fail('The relay failure must end the worker loop.');
        } catch (HeaderException) {
        }

        self::assertSame(1, $callCount);
    }

    public function testServerSuperglobalNotMutatedByRequestHandling(): void
    {
        $this->setupSuccessfulRequest();

        $serverBeforeLoop = $_SERVER;

        $this->makeWorker()->start();

        $this->assertSame($serverBeforeLoop, $_SERVER);
    }

    public function testWaitRequestExceptionDoesNotDispatchRequestOrResponseEvents(): void
    {
        $callCount = 0;
        $this->spiralHttpWorker
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
