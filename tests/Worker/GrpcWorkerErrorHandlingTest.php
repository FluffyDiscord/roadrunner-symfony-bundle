<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntimeFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\CrashRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\FaultingEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\UndecodableRequestEchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\UndecodableRequestEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\FailRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Google\Rpc\Status;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ErrorThrowingEchoService extends EchoService
{
    public function Crash(ContextInterface $ctx, CrashRequest $in): PingResponse
    {
        throw new \Error('php error');
    }
}

class UndecodableInputEchoService extends EchoService
{
}

/** TC-13 / TC-14 */
#[AllowMockObjectsWithoutExpectations]
class GrpcWorkerErrorHandlingTest extends AbstractGrpcWorkerTestCase
{
    public function testUnhandledExceptionErrorsLogsCapturesAndReboots(): void
    {
        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Crash')], debug: false);

        $this->rrWorker->expects($this->once())->method('error')->with('crash');
        $this->rrWorker->expects($this->never())->method('stop');
        $this->kernel->expects($this->once())->method('reboot')->with(null);

        $worker->start();

        self::assertStringContainsString('crash', implode("\n", $worker->loggedErrors));
    }

    public function testDebugModeSendsTheFullThrowableToTheClient(): void
    {
        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Crash')], debug: true);

        $this->rrWorker->expects($this->once())->method('error')->with($this->stringContains('RuntimeException'));

        $worker->start();
    }

    public function testUnhandledExceptionIsCapturedToSentry(): void
    {
        $sentryHub = $this->createMock(\Sentry\State\HubInterface::class);
        $sentryHub->expects($this->once())->method('captureException');

        $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Crash')], sentryHub: $sentryHub)->start();
    }

    public function testPhpErrorAdditionallyStopsTheWorker(): void
    {
        $this->registerRuntimeFactory($this->eventDispatcher, new ErrorThrowingEchoService());

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Crash')]);

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $worker->start();
    }

    public function testRuntimeIsRebuiltFromTheNewContainerAfterReboot(): void
    {
        $rebootedDispatcher = new EventDispatcher();
        $rebootedEvents = [];
        $rebootedDispatcher->addListener(WorkerResponseSentEvent::class, static function (object $event) use (&$rebootedEvents): void {
            $rebootedEvents[] = $event;
        });

        $this->kernel->method('reboot')->willReturnCallback(function () use ($rebootedDispatcher): void {
            $this->registerRuntimeFactory($rebootedDispatcher);
        });

        $worker = $this->makeWorker([
            $this->makeFramePayload('bundle.test.Echo', 'Crash'),
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
        ]);

        $worker->start();

        self::assertCount(1, $rebootedEvents, 'the frame after the reboot must dispatch into the rebooted container\'s dispatcher');
    }

    public function testResetterFailureLogsAndStops(): void
    {
        $this->servicesResetter->method('reset')->willThrowException(new \RuntimeException('reset broke'));

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $worker->start();

        self::assertStringContainsString('reset broke', implode("\n", $worker->loggedErrors));
    }

    public function testRebootFailureLogsFatalCleanupAndStops(): void
    {
        $this->kernel->method('reboot')->willThrowException(new \RuntimeException('reboot broke'));

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Crash')]);

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $worker->start();

        self::assertStringContainsString('Fatal worker cleanup error', implode("\n", $worker->loggedErrors));
    }

    public function testRespondFailureLogsAndStopsWithoutPropagating(): void
    {
        $this->rrWorker->method('respond')->willThrowException(new \RuntimeException('relay broke'));

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $worker->start();

        self::assertStringContainsString('Failed to answer gRPC frame', implode("\n", $worker->loggedErrors));
    }

    public function testResponseSentListenerFailureLeavesExactlyOneAnswer(): void
    {
        $this->eventDispatcher->addListener(WorkerResponseSentEvent::class, static function (): void {
            throw new \DomainException('listener exploded');
        });

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);

        $this->rrWorker->expects($this->once())->method('respond');
        $this->rrWorker->expects($this->never())->method('error');
        $this->rrWorker->expects($this->never())->method('stop');

        $worker->start();

        self::assertStringContainsString('WorkerResponseSentEvent listener threw', implode("\n", $worker->loggedErrors));
    }

    public function testFailedEventListenerFailureStillAnswersNotFound(): void
    {
        $this->eventDispatcher->addListener(GrpcCallFailedEvent::class, static function (): void {
            throw new \DomainException('failed listener exploded');
        });

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Nope', 'Ping')]);

        $this->rrWorker->expects($this->once())->method('respond');

        $worker->start();

        self::assertStringContainsString('gRPC failed-event listener threw', implode("\n", $worker->loggedErrors));
    }

    /** TC-11 edge — a handler fault is a gRPC INTERNAL status AND a server-side incident */
    public function testHandlerFaultAnswersInternalStatusLogsCapturesAndReboots(): void
    {
        $this->registerRuntimeFactory($this->eventDispatcher, new FaultingEchoService());

        $responses = [];
        $this->rrWorker->method('respond')->willReturnCallback(static function (\Spiral\RoadRunner\Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });
        $sentryHub = $this->createMock(\Sentry\State\HubInterface::class);
        $sentryHub->expects($this->once())->method('captureException');
        $this->kernel->expects($this->once())->method('reboot');
        $this->rrWorker->expects($this->never())->method('error');
        $this->rrWorker->expects($this->never())->method('stop');

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')], sentryHub: $sentryHub);
        $worker->start();

        self::assertCount(1, $responses);
        $document = json_decode($responses[0]->header, true);
        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::INTERNAL, $status->getCode());
        self::assertStringContainsString('handler fault', implode("\n", $worker->loggedErrors));
    }

    /**
     * TC-07 third variant — a request body the message type refuses to parse is a quiet INTERNAL
     * client error. The refusal comes from the fixture message rather than from a byte string a
     * given protobuf release happens to reject, so the case cannot quietly stop testing itself
     * when the wire parser grows more tolerant.
     */
    public function testUndecodableRequestBodyAnswersInternalWithoutRebootOrSentry(): void
    {
        $responses = [];
        $this->rrWorker->method('respond')->willReturnCallback(static function (\Spiral\RoadRunner\Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });
        $sentryHub = $this->createMock(\Sentry\State\HubInterface::class);
        $sentryHub->expects($this->never())->method('captureException');
        $this->kernel->expects($this->never())->method('reboot');

        $handler = new UndecodableRequestEchoService();
        $this->registerRuntimeFactory($this->eventDispatcher, $handler, UndecodableRequestEchoInterface::class);
        $this->recordDispatchedEvents();

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.UndecodableEcho', 'Ping', 'any-non-empty-body')], sentryHub: $sentryHub);
        $worker->start();

        self::assertCount(1, $responses);
        $document = json_decode($responses[0]->header, true);
        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::INTERNAL, $status->getCode());

        self::assertFalse($handler->wasCalled);

        $failedEvents = array_values(array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof GrpcCallFailedEvent));
        self::assertCount(1, $failedEvents);
        self::assertNull($failedEvents[0]->request);
    }

}
