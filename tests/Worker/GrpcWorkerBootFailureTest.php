<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use Google\Rpc\Status;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\GRPC\StatusCode;
use Spiral\RoadRunner\Payload;

/** TC-16 / TC-17 */
#[AllowMockObjectsWithoutExpectations]
class GrpcWorkerBootFailureTest extends AbstractGrpcWorkerTestCase
{
    /**
     * @param list<Payload> $responses
     */
    private function assertUnavailableAnswer(array $responses, string $expectedMessagePrefix): void
    {
        self::assertCount(1, $responses);
        $document = json_decode($responses[0]->header, true);
        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::UNAVAILABLE, $status->getCode());
        self::assertStringStartsWith($expectedMessagePrefix, $status->getMessage());
    }

    public function testKernelBootFailureAnswersOnePayloadWithUnavailableAndReturns(): void
    {
        $responses = [];
        $this->rrWorker->method('respond')->willReturnCallback(static function (Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('boot broken'));

        $worker = $this->makeWorker([
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
        ]);

        $worker->start();

        $this->assertUnavailableAnswer($responses, 'Worker boot failed');
        self::assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
        self::assertSame(1, count($responses), 'only one payload is answered; the worker exits so RR respawns and retries boot');
        self::assertNotSame([], $worker->payloadQueue, 'the second payload must be left for the respawned worker');
    }

    public function testDebugBootFailureMessageCarriesTheThrowable(): void
    {
        $responses = [];
        $this->rrWorker->method('respond')->willReturnCallback(static function (Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('boot broken'));

        $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')], debug: true)->start();

        $this->assertUnavailableAnswer($responses, 'Worker boot failed: RuntimeException');
    }

    public function testRoutingTableFailureTakesTheSameUnavailablePath(): void
    {
        $responses = [];
        $this->rrWorker->method('respond')->willReturnCallback(static function (Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });
        $this->kernelContainer->set(\FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntimeFactory::class, null);

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);

        $worker->start();

        $this->assertUnavailableAnswer($responses, 'Worker boot failed');
    }

    public function testBootListenerFailureDegradesAndKeepsServing(): void
    {
        $this->eventDispatcher->addListener(WorkerBootingEvent::class, static function (): void {
            throw new \RuntimeException('boot listener exploded');
        });

        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);

        $this->rrWorker->expects($this->once())->method('respond');

        $worker->start();

        self::assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
    }

    public function testBootFailureIsReportedToSentry(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('boot broken'));
        $sentryHub = $this->createMock(\Sentry\State\HubInterface::class);
        $sentryHub->expects($this->once())->method('captureException');

        $this->makeWorker([], sentryHub: $sentryHub)->start();
    }
}
