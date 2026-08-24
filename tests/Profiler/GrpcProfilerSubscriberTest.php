<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Profiler;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcDataCollector;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcProfilerSubscriber;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Stopwatch\Stopwatch;

/** TC-20 */
#[AllowMockObjectsWithoutExpectations]
class GrpcProfilerSubscriberTest extends BaseTestCase
{
    private GrpcDataCollector $dataCollector;
    private Profiler&MockObject $profiler;
    private RequestStack $virtualRequestStack;
    private EnvironmentInterface&MockObject $environment;

    /** @var list<array{Request, Response, ?\Throwable}> */
    private array $collected = [];

    /** @var list<Request> */
    private array $stackWhenCollecting = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataCollector = new GrpcDataCollector();
        $this->virtualRequestStack = new RequestStack();
        $this->environment = $this->createMock(EnvironmentInterface::class);
        $this->environment->method('getMode')->willReturn(Mode::MODE_GRPC);
        $this->collected = [];
        $this->stackWhenCollecting = [];

        $this->profiler = $this->createMock(Profiler::class);
        $this->profiler->method('collect')->willReturnCallback(function (Request $request, Response $response, ?\Throwable $exception = null): Profile {
            $this->collected[] = [$request, $response, $exception];
            $currentRequest = $this->virtualRequestStack->getCurrentRequest();

            if ($currentRequest !== null) {
                $this->stackWhenCollecting[] = $currentRequest;
            }

            return new Profile('token1');
        });
    }

    private function makeSubscriber(?Stopwatch $stopwatch = null): GrpcProfilerSubscriber
    {
        return new GrpcProfilerSubscriber(
            dataCollector: $this->dataCollector,
            profiler: $this->profiler,
            virtualRequestStack: $this->virtualRequestStack,
            stopwatch: $stopwatch,
            tokenStorage: null,
            environment: $this->environment,
            redactedMetadataKeys: ['authorization'],
        );
    }

    private function makeReceivedEvent(): GrpcCallReceivedEvent
    {
        $method = Method::parse(new \ReflectionMethod(EchoInterface::class, 'Ping'));
        $metadata = new GrpcMetadata(['authorization' => ['Bearer secret'], 'x-test' => ['1'], 'x-blob-bin' => ['abcd']]);
        $context = new Context([GrpcMetadata::class => $metadata]);

        return new GrpcCallReceivedEvent('bundle.test.Echo', 'Ping', new EchoService(), $method, $context, new PingRequest()->setMessage('hi'));
    }

    public function testHappyPathCollectsAFullProfileWithThePoppedVirtualRequest(): void
    {
        $stopwatch = new Stopwatch();
        $subscriber = $this->makeSubscriber($stopwatch);

        $this->profiler->expects($this->once())->method('saveProfile');

        $subscriber->onRequestReceived();
        self::assertInstanceOf(GrpcRequest::class, $this->virtualRequestStack->getCurrentRequest());

        $subscriber->onCallReceived($this->makeReceivedEvent());
        $subscriber->onCallCompleted(new GrpcCallCompletedEvent('bundle.test.Echo', 'Ping', new Context([]), new PingRequest(), new PingResponse()->setMessage('hi'), 1.5));
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_GRPC));

        self::assertCount(1, $this->collected);
        self::assertSame([], $this->stackWhenCollecting, 'the virtual request must be popped before Profiler::collect');
        self::assertNull($this->virtualRequestStack->getCurrentRequest());

        [$request, $response] = $this->collected[0];
        self::assertInstanceOf(GrpcRequest::class, $request);
        self::assertSame('grpc://bundle.test.Echo/Ping', $request->getUri());
        self::assertSame('GRPC', $request->getMethod());
        self::assertSame('grpc', $request->attributes->get('_virtual_type'));
        self::assertSame(EchoService::class . '::Ping', $request->attributes->get('_controller'));
        self::assertSame([], $request->headers->all());
        self::assertSame(200, $response->getStatusCode());

        self::assertSame('OK', $this->dataCollector->getWorkerStatusName());
        self::assertStringContainsString('"message":"hi"', (string) $this->dataCollector->getRequestJson());
        self::assertStringContainsString('"message":"hi"', (string) $this->dataCollector->getResponseJson());
        self::assertSame([GrpcDataCollector::REDACTED_VALUE], $this->dataCollector->getMetadata()['authorization']);
        self::assertSame(['1'], $this->dataCollector->getMetadata()['x-test']);
        self::assertSame(['<binary, 4 bytes>'], $this->dataCollector->getMetadata()['x-blob-bin']);
    }

    public function testGrpcStatusFailureMapsToHttp400(): void
    {
        $subscriber = $this->makeSubscriber();

        $subscriber->onRequestReceived();
        $subscriber->onCallReceived($this->makeReceivedEvent());
        $subscriber->onCallFailed(new GrpcCallFailedEvent('bundle.test.Echo', 'Fail', new Context([]), new PingRequest(), GRPCException::create('boom', StatusCode::INVALID_ARGUMENT), StatusCode::INVALID_ARGUMENT, 1.0));
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_GRPC));

        self::assertSame(400, $this->collected[0][1]->getStatusCode());
        self::assertSame('INVALID_ARGUMENT', $this->dataCollector->getWorkerStatusName());
    }

    public function testUnroutableFailureUsesTheEventNames(): void
    {
        $subscriber = $this->makeSubscriber();

        $subscriber->onRequestReceived();
        $subscriber->onCallFailed(new GrpcCallFailedEvent('bundle.test.Nope', 'Ping', null, null, new \RuntimeException('nf'), StatusCode::NOT_FOUND, 1.0));
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_GRPC));

        $request = $this->collected[0][0];
        self::assertSame('grpc://bundle.test.Nope/Ping', $request->getUri());
        self::assertFalse($request->attributes->has('_controller'));
    }

    public function testMalformedFrameKeepsTheUnknownProfileUri(): void
    {
        $subscriber = $this->makeSubscriber();

        $subscriber->onRequestReceived();
        $subscriber->onCallFailed(new GrpcCallFailedEvent('', '', null, null, new \RuntimeException('malformed'), StatusCode::INVALID_ARGUMENT, 1.0));
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_GRPC));

        self::assertSame('grpc://unknown', $this->collected[0][0]->getUri());
        self::assertSame(400, $this->collected[0][1]->getStatusCode());
    }

    public function testAbandonedFrameIsPersistedAsFailedOnReset(): void
    {
        $subscriber = $this->makeSubscriber();

        $subscriber->onRequestReceived();
        $subscriber->onCallReceived($this->makeReceivedEvent());
        $subscriber->reset();

        self::assertCount(1, $this->collected);
        self::assertSame(500, $this->collected[0][1]->getStatusCode());
        self::assertStringContainsString('no response was sent', (string) $this->dataCollector->getError());
        self::assertNull($this->virtualRequestStack->getCurrentRequest());
    }

    public function testOtherWorkerResponsesAreIgnored(): void
    {
        $subscriber = $this->makeSubscriber();

        $subscriber->onRequestReceived();
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_JOBS));

        self::assertCount(0, $this->collected);
    }

    public function testNonGrpcWorkerModeNeverPushesNorCollects(): void
    {
        $httpEnvironment = $this->createMock(EnvironmentInterface::class);
        $httpEnvironment->method('getMode')->willReturn(Mode::MODE_HTTP);

        $subscriber = new GrpcProfilerSubscriber(
            dataCollector: $this->dataCollector,
            profiler: $this->profiler,
            virtualRequestStack: $this->virtualRequestStack,
            stopwatch: null,
            tokenStorage: null,
            environment: $httpEnvironment,
            redactedMetadataKeys: [],
        );

        $subscriber->onRequestReceived();
        $subscriber->reset();

        self::assertNull($this->virtualRequestStack->getCurrentRequest());
        self::assertCount(0, $this->collected);
    }

    public function testNullStackAndStopwatchStillCollect(): void
    {
        $subscriber = new GrpcProfilerSubscriber(
            dataCollector: $this->dataCollector,
            profiler: $this->profiler,
            virtualRequestStack: null,
            stopwatch: null,
            tokenStorage: null,
            environment: $this->environment,
            redactedMetadataKeys: [],
        );

        $subscriber->onRequestReceived();
        $subscriber->onCallReceived($this->makeReceivedEvent());
        $subscriber->onCallCompleted(new GrpcCallCompletedEvent('bundle.test.Echo', 'Ping', new Context([]), new PingRequest(), new PingResponse(), 1.0));
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_GRPC));

        self::assertCount(1, $this->collected);
    }
}
