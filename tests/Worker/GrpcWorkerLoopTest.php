<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Google\Rpc\Status;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\GRPC\StatusCode;
use Spiral\RoadRunner\Payload;

/** TC-10 / TC-11 / TC-12 */
#[AllowMockObjectsWithoutExpectations]
class GrpcWorkerLoopTest extends AbstractGrpcWorkerTestCase
{
    /** @var list<Payload> */
    private array $responses = [];

    /** @var list<string> */
    private array $errors = [];

    private function captureAnswers(): void
    {
        $this->rrWorker->method('respond')->willReturnCallback(function (Payload $payload): void {
            $this->responses[] = $payload;
        });
        $this->rrWorker->method('error')->willReturnCallback(function (string $message): void {
            $this->errors[] = $message;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStatusHeader(Payload $payload): array
    {
        $document = json_decode($payload->header, true);
        self::assertIsArray($document);

        return $document;
    }

    public function testPingFrameRespondsWithEncodedResponseAndHeader(): void
    {
        $this->captureAnswers();
        $this->recordDispatchedEvents();

        $requestBody = new PingRequest()->setMessage('hi')->serializeToString();
        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping', $requestBody)]);

        $this->kernel->expects($this->atLeast(2))->method('boot');
        $this->servicesResetter->expects($this->once())->method('reset');
        $this->rrWorker->expects($this->never())->method('stop');

        $worker->start();

        self::assertCount(1, $this->responses);
        $response = new PingResponse();
        $response->mergeFromString($this->responses[0]->body);
        self::assertSame('hi', $response->getMessage());
        $headerDocument = json_decode($this->responses[0]->header, true);
        self::assertIsArray($headerDocument);
        self::assertSame(['x-echo' => '1'], json_decode((string) $headerDocument['headers'], true));

        $eventClasses = $this->dispatchedEventClasses();
        self::assertSame([
            \FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent::class,
            WorkerRequestReceivedEvent::class,
            GrpcCallReceivedEvent::class,
            GrpcCallCompletedEvent::class,
            WorkerResponseSentEvent::class,
        ], $eventClasses);
        self::assertSame(1, $worker->shutdownRegistrations);
    }

    public function testTwoFramesYieldTwoResponsesAndOneShutdownRegistration(): void
    {
        $this->captureAnswers();

        $worker = $this->makeWorker([
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
        ]);

        $worker->start();

        self::assertCount(2, $this->responses);
        self::assertSame(1, $worker->shutdownRegistrations);
    }

    public function testGrpcStatusExceptionAnswersWithErrorHeaderWithoutRebootOrLog(): void
    {
        $this->captureAnswers();

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Fail')]);

        $this->kernel->expects($this->never())->method('reboot');
        $this->rrWorker->expects($this->never())->method('stop');

        $worker->start();

        self::assertCount(1, $this->responses);
        $document = $this->decodeStatusHeader($this->responses[0]);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::INVALID_ARGUMENT, $status->getCode());
        self::assertSame('boom', $status->getMessage());
        self::assertSame([], $worker->loggedErrors);
    }

    public function testUnknownServiceAnswersNotFoundAndDispatchesWorkerFailedEvent(): void
    {
        $this->captureAnswers();
        $this->recordDispatchedEvents();

        $worker = $this->makeWorker([
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
            $this->makeFramePayload('bundle.test.Nope', 'Ping', '', ['x-test' => ['1']]),
        ]);

        $worker->start();

        self::assertCount(2, $this->responses);
        $document = $this->decodeStatusHeader($this->responses[1]);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::NOT_FOUND, $status->getCode());

        $failedEvents = array_values(array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof GrpcCallFailedEvent));
        self::assertCount(1, $failedEvents);
        self::assertSame('bundle.test.Nope', $failedEvents[0]->serviceName);
        self::assertNotNull($failedEvents[0]->context);
        self::assertNull($failedEvents[0]->request);
    }

    public function testUnknownMethodAnswersNotFound(): void
    {
        $this->captureAnswers();

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Nope')]);

        $worker->start();

        $document = $this->decodeStatusHeader($this->responses[0]);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::NOT_FOUND, $status->getCode());
    }

    public function testMalformedHeaderAnswersInvalidArgumentWithoutReboot(): void
    {
        $this->captureAnswers();
        $this->recordDispatchedEvents();

        $worker = $this->makeWorker([new Payload('', 'not json')]);

        $this->kernel->expects($this->never())->method('reboot');

        $worker->start();

        self::assertCount(1, $this->responses);
        $document = $this->decodeStatusHeader($this->responses[0]);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::INVALID_ARGUMENT, $status->getCode());
        self::assertSame('Malformed gRPC frame', $status->getMessage());
        self::assertNotSame([], $worker->loggedErrors);

        $failedEvents = array_values(array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof GrpcCallFailedEvent));
        self::assertCount(1, $failedEvents);
        self::assertSame('', $failedEvents[0]->serviceName);
        self::assertNull($failedEvents[0]->context);
    }
}
