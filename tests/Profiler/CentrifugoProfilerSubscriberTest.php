<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Profiler;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoDataCollector;
use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoProfilerSubscriber;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use RoadRunner\Centrifugo\Request\Connect;
use RoadRunner\Centrifugo\Request\Invalid;
use RoadRunner\Centrifugo\Request\Publish;
use RoadRunner\Centrifugo\Request\RPC;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[AllowMockObjectsWithoutExpectations]
class CentrifugoProfilerSubscriberTest extends BaseTestCase
{
    private CentrifugoDataCollector $dataCollector;
    private WorkerInterface $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataCollector = new CentrifugoDataCollector();
        $this->worker = $this->createMock(WorkerInterface::class);
    }

    public function testGetSubscribedEventsReturnsExpectedMapping(): void
    {
        $events = CentrifugoProfilerSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(WorkerRequestReceivedEvent::class, $events);
        self::assertArrayHasKey(ConnectEvent::class, $events);
        self::assertArrayHasKey(PublishEvent::class, $events);
        self::assertArrayHasKey(RPCEvent::class, $events);
        self::assertArrayHasKey(InvalidEvent::class, $events);
        self::assertArrayHasKey(WorkerResponseSentEvent::class, $events);

        self::assertSame(PHP_INT_MAX, $events[WorkerRequestReceivedEvent::class][1]);
        self::assertSame(PHP_INT_MIN, $events[WorkerResponseSentEvent::class][1]);
    }

    public function testOnCentrifugoEventExtractsEventTypeName(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile')->with(
            self::callback(fn($profile) => $profile->getUrl() === '/centrifugo/publish'),
        );

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        self::assertSame('Publish', $this->dataCollector->getEventType());
    }

    public function testOnCentrifugoEventStripsEventSuffix(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new ConnectEvent(new Connect($this->worker, 'c', 'ws', 'json', 'json', [], null, null, [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        self::assertSame('Connect', $this->dataCollector->getEventType());
    }

    public function testOnResponseSentIgnoresNonCentrifugeMode(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::never())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        self::assertFalse($this->dataCollector->hasData());
    }

    public function testSuccessfulRequestProfileMarkedAsSuccess(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        self::assertTrue($this->dataCollector->isSuccess());
        self::assertNull($this->dataCollector->getError());
    }

    public function testResetPersistsFailureProfileWhenPendingCentrifugoRequest(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->reset();

        self::assertFalse($this->dataCollector->isSuccess());
        self::assertSame('Request failed – an exception occurred', $this->dataCollector->getError());
    }

    public function testNextRequestDetectsFailedPreviousRequest(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onRequestReceived();

        self::assertFalse($this->dataCollector->isSuccess());
        self::assertSame('Request failed – no response was sent', $this->dataCollector->getError());
    }

    public function testNoProfileSavedWhenProfilerIsNull(): void
    {
        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, null);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        self::assertFalse($this->dataCollector->hasData());
    }

    public function testResetClearsAllState(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        $this->dataCollector->reset();
        $subscriber->reset();

        $profiler->expects(self::never())->method('saveProfile');
    }

    public function testNonCentrifugoRequestNotTrackedAsFailure(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::never())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onRequestReceived();
    }

    public function testInvalidEventTypeExtracted(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile')->with(
            self::callback(fn($profile) => $profile->getUrl() === '/centrifugo/invalid'),
        );

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(new InvalidEvent(new Invalid(new \RuntimeException('bad'))));
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        self::assertSame('Invalid', $this->dataCollector->getEventType());
    }

    public function testRpcEventTypeExtracted(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile');

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new RPCEvent(new RPC($this->worker, 'c', 'ws', 'json', 'json', 'u', 'method', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));

        self::assertSame('RPC', $this->dataCollector->getEventType());
    }

    public function testProfileHasCorrectMetadata(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile')->with(
            self::callback(function ($profile) {
                return $profile->getMethod() === 'CENTRIFUGO'
                    && $profile->getStatusCode() === 200
                    && $profile->getIp() === '127.0.0.1'
                    && str_starts_with($profile->getUrl(), '/centrifugo/');
            }),
        );

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->onResponseSent(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));
    }

    public function testFailedProfileHasStatusCode500(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('saveProfile')->with(
            self::callback(fn($profile) => $profile->getStatusCode() === 500),
        );

        $subscriber = new CentrifugoProfilerSubscriber($this->dataCollector, $profiler);

        $subscriber->onRequestReceived();
        $subscriber->onCentrifugoEvent(
            new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []))
        );
        $subscriber->reset();
    }
}
