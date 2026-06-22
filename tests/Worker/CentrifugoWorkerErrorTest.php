<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * @see \FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker
 * @see docs/specs/graceful-error-handling.md "Centrifugo worker (delta)"
 */
#[AllowMockObjectsWithoutExpectations]
class CentrifugoWorkerErrorTest extends AbstractCentrifugoWorkerTestCase
{
    public function testConnectAndSubscribeMapToDisconnect(): void
    {
        $worker = $this->makeWorker();
        $this->assertSame('disconnect', $worker->callChooseFailureAction($this->makeConnect()));
        $this->assertSame('disconnect', $worker->callChooseFailureAction($this->makeSubscribe()));
    }

    public function testOperationRequestsMapToError(): void
    {
        $worker = $this->makeWorker();
        $this->assertSame('error', $worker->callChooseFailureAction($this->makeRpc()));
        $this->assertSame('error', $worker->callChooseFailureAction($this->makePublish()));
        $this->assertSame('error', $worker->callChooseFailureAction($this->makeRefresh()));
        $this->assertSame('error', $worker->callChooseFailureAction($this->makeSubRefresh()));
    }

    public function testInvalidMapsToNone(): void
    {
        $worker = $this->makeWorker();
        $this->assertSame('none', $worker->callChooseFailureAction($this->makeInvalid()));
    }

    public function testClientMessageInDebugShowsClassAndMessageWithoutTrace(): void
    {
        $worker = $this->makeWorker(debug: true);
        $message = $worker->callClientMessage(new \RuntimeException('kaboom detail'));

        $this->assertStringContainsString('RuntimeException', $message);
        $this->assertStringContainsString('kaboom detail', $message);
        $this->assertStringNotContainsString('Stack trace', $message); // never the trace over the wire
    }

    public function testClientMessageInProdIsGeneric(): void
    {
        $worker = $this->makeWorker(debug: false);
        $this->assertSame('Unexpected system error', $worker->callClientMessage(new \RuntimeException('secret detail')));
    }

    public function testShutdownLogsFatalWhenRequestInFlight(): void
    {
        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown(true, false, $this->makeConnect(), [
            'message' => 'Boom fatal',
            'file'    => '/app/Handler.php',
            'line'    => 12,
        ]);

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'Boom fatal')),
        );
    }

    public function testShutdownLogsGenericForBareDieExit(): void
    {
        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown(true, false, $this->makeRpc(), null);

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'die/exit')),
        );
    }

    public function testShutdownNoopWhenAlreadyResponded(): void
    {
        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown(true, true, $this->makeConnect(), null);
        $this->assertSame([], $worker->loggedErrors);
    }

    public function testShutdownNoopWhenNotHandlingRequest(): void
    {
        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown(false, false, $this->makeConnect(), null);
        $this->assertSame([], $worker->loggedErrors);
    }

    public function testShutdownNoopWhenNoRequest(): void
    {
        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown(true, false, null, null);
        $this->assertSame([], $worker->loggedErrors);
    }

    public function testShutdownRegisteredOncePerInstance(): void
    {
        $worker = $this->makeWorker(); // empty queue → waitRequest() returns null → loop is skipped
        $worker->start();
        $worker->start();

        $this->assertSame(1, $worker->shutdownRegistrations);
        $this->assertInstanceOf(\Closure::class, $worker->registeredShutdown);
    }

    public function testHardErrorStopsWorkerWithoutSecondFrame(): void
    {
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event instanceof InvalidEvent ? throw new \Error('boom hard') : $event,
        );

        $this->goridgeWorker->expects($this->atLeastOnce())->method('stop');
        $this->goridgeWorker->expects($this->never())->method('error'); // one frame: STDERR, not a goridge error()

        $worker = $this->makeWorker(debug: true, requests: [$this->makeInvalid()]);
        $worker->start();

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'boom hard')),
        );
    }

    public function testExceptionDoesNotStopWorkerWithoutSecondFrame(): void
    {
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn(object $event): object => $event instanceof InvalidEvent ? throw new \RuntimeException('boom soft') : $event,
        );

        $this->goridgeWorker->expects($this->never())->method('stop');
        $this->goridgeWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true, requests: [$this->makeInvalid()]);
        $worker->start();

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'boom soft')),
        );
    }
}
