<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use FluffyDiscord\RoadRunnerBundle\Grpc\Tracing\GrpcTracingListener;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\AbstractLogger;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\StatusCode;

/** TC-22 */
#[AllowMockObjectsWithoutExpectations]
class GrpcTracingListenerTest extends BaseTestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logRecords = [];

    private function makeLogger(): AbstractLogger
    {
        return new class($this->logRecords) extends AbstractLogger {
            /** @param list<array{string, string, array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    private function makeReceivedEvent(): GrpcCallReceivedEvent
    {
        $method = Method::parse(new \ReflectionMethod(EchoInterface::class, 'Ping'));
        $metadata = new GrpcMetadata(['authorization' => ['Bearer secret'], 'x-test' => ['1']]);

        return new GrpcCallReceivedEvent('bundle.test.Echo', 'Ping', new EchoService(), $method, new Context([GrpcMetadata::class => $metadata]), new PingRequest());
    }

    public function testReceivedLogsInfoWithMetadataKeysOnly(): void
    {
        $listener = new GrpcTracingListener($this->makeLogger(), null);

        $listener->onCallReceived($this->makeReceivedEvent());

        self::assertCount(1, $this->logRecords);
        [$level, $message, $context] = $this->logRecords[0];
        self::assertSame('info', $level);
        self::assertSame('gRPC call received', $message);
        self::assertSame(['authorization', 'x-test'], $context['metadata_keys']);
        self::assertStringNotContainsString('secret', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function testCompletedLogsInfoWithDuration(): void
    {
        $listener = new GrpcTracingListener($this->makeLogger(), null);

        $listener->onCallCompleted(new GrpcCallCompletedEvent('bundle.test.Echo', 'Ping', new Context([]), new PingRequest(), new PingResponse(), 2.5));

        [$level, $message, $context] = $this->logRecords[0];
        self::assertSame('info', $level);
        self::assertSame('gRPC call completed', $message);
        self::assertSame(2.5, $context['duration_ms']);
    }

    public function testFailedLogsWarningWithStatusAndException(): void
    {
        $listener = new GrpcTracingListener($this->makeLogger(), null);

        $listener->onCallFailed(new GrpcCallFailedEvent('bundle.test.Echo', 'Fail', null, null, new \RuntimeException('boom'), StatusCode::UNKNOWN, 1.0));

        [$level, $message, $context] = $this->logRecords[0];
        self::assertSame('warning', $level);
        self::assertSame('gRPC call failed', $message);
        self::assertSame(StatusCode::UNKNOWN, $context['worker_status_code']);
        self::assertStringContainsString('RuntimeException: boom', (string) $context['exception']);
    }

    public function testNullLoggerAndHubAreTolerated(): void
    {
        $listener = new GrpcTracingListener(null, null);

        $listener->onCallReceived($this->makeReceivedEvent());

        self::assertSame([], $this->logRecords);
    }

    public function testSentryBreadcrumbsAreAddedWhenTheHubIsPresent(): void
    {
        $sentryHub = $this->createMock(\Sentry\State\HubInterface::class);
        $sentryHub->expects($this->once())->method('addBreadcrumb');

        new GrpcTracingListener(null, $sentryHub)->onCallReceived($this->makeReceivedEvent());
    }
}
