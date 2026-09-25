<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Event\Centrifugo;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\Refusal;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefusalType;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Payload\PublishResponse;
use RoadRunner\Centrifugo\Payload\RefreshResponse;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Payload\SubRefreshResponse;
use RoadRunner\Centrifugo\Payload\SubscribeResponse;
use RoadRunner\Centrifugo\Request\Connect;
use RoadRunner\Centrifugo\Request\Invalid;
use RoadRunner\Centrifugo\Request\Publish;
use RoadRunner\Centrifugo\Request\RPC;
use RoadRunner\Centrifugo\Request\Refresh;
use RoadRunner\Centrifugo\Request\SubRefresh;
use RoadRunner\Centrifugo\Request\Subscribe;
use Spiral\RoadRunner\WorkerInterface;

#[AllowMockObjectsWithoutExpectations]
class CentrifugoEventTest extends BaseTestCase
{
    private WorkerInterface $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = $this->createMock(WorkerInterface::class);
    }

    public static function eventProvider(): iterable
    {
        yield 'ConnectEvent' => [
            ConnectEvent::class,
            ConnectResponse::class,
            PublishResponse::class,
        ];
        yield 'PublishEvent' => [
            PublishEvent::class,
            PublishResponse::class,
            ConnectResponse::class,
        ];
        yield 'RefreshEvent' => [
            RefreshEvent::class,
            RefreshResponse::class,
            ConnectResponse::class,
        ];
        yield 'SubRefreshEvent' => [
            SubRefreshEvent::class,
            SubRefreshResponse::class,
            ConnectResponse::class,
        ];
        yield 'SubscribeEvent' => [
            SubscribeEvent::class,
            SubscribeResponse::class,
            ConnectResponse::class,
        ];
        yield 'RPCEvent' => [
            RPCEvent::class,
            RPCResponse::class,
            ConnectResponse::class,
        ];
    }

    private function makeRequest(string $eventClass): object
    {
        return match ($eventClass) {
            ConnectEvent::class    => new Connect($this->worker, 'c', 'ws', 'json', 'json', [], null, null, [], []),
            PublishEvent::class    => new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], [], []),
            RefreshEvent::class    => new Refresh($this->worker, 'c', 'ws', 'json', 'json', 'u', [], []),
            SubRefreshEvent::class => new SubRefresh($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', [], []),
            SubscribeEvent::class  => new Subscribe($this->worker, 'c', 'ws', 'json', 'json', 'u', 'ch', '', [], [], []),
            RPCEvent::class        => new RPC($this->worker, 'c', 'ws', 'json', 'json', 'u', 'method', [], [], []),
        };
    }

    #[DataProvider('eventProvider')]
    public function testGetRequestReturnsInjectedRequest(string $eventClass, string $responseClass, string $wrongResponseClass): void
    {
        $request = $this->makeRequest($eventClass);
        $event = new $eventClass($request);

        self::assertSame($request, $event->getRequest());
    }

    #[DataProvider('eventProvider')]
    public function testResponseIsNullByDefault(string $eventClass, string $responseClass, string $wrongResponseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));

        self::assertNull($event->getResponse());
    }

    #[DataProvider('eventProvider')]
    public function testSetResponseWithCorrectType(string $eventClass, string $responseClass, string $wrongResponseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));
        $response = new $responseClass();

        $result = $event->setResponse($response);

        self::assertSame($response, $event->getResponse());
        self::assertSame($event, $result);
    }

    #[DataProvider('eventProvider')]
    public function testSetResponseToNull(string $eventClass, string $responseClass, string $wrongResponseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));
        $event->setResponse(new $responseClass());
        $event->setResponse(null);

        self::assertNull($event->getResponse());
    }

    #[DataProvider('eventProvider')]
    public function testSetResponseWithWrongTypeThrowsInvalidArgumentException(string $eventClass, string $responseClass, string $wrongResponseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));

        $this->expectException(\InvalidArgumentException::class);
        $event->setResponse(new $wrongResponseClass());
    }

    public static function refusableEventProvider(): iterable
    {
        yield 'ConnectEvent'   => [ConnectEvent::class, ConnectResponse::class];
        yield 'PublishEvent'   => [PublishEvent::class, PublishResponse::class];
        yield 'SubscribeEvent' => [SubscribeEvent::class, SubscribeResponse::class];
        yield 'RPCEvent'       => [RPCEvent::class, RPCResponse::class];
    }

    #[DataProvider('refusableEventProvider')]
    public function testRejectRecordsErrorAndStopsPropagation(string $eventClass, string $responseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));

        $event->reject(1000, 'slow down', true);

        self::assertEquals(new Refusal(RefusalType::Error, 1000, 'slow down', true), $event->getRefusal());
        self::assertTrue($event->isPropagationStopped());
        self::assertNull($event->getResponse());
    }

    #[DataProvider('refusableEventProvider')]
    public function testDisconnectRecordsDisconnectAndStopsPropagation(string $eventClass, string $responseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));

        $event->disconnect(4500, 'banned');

        self::assertEquals(new Refusal(RefusalType::Disconnect, 4500, 'banned'), $event->getRefusal());
        self::assertTrue($event->isPropagationStopped());
    }

    #[DataProvider('refusableEventProvider')]
    public function testRefusalIsNullByDefault(string $eventClass, string $responseClass): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));

        self::assertNull($event->getRefusal());
        self::assertFalse($event->isPropagationStopped());
    }

    public static function conflictingAnswerProvider(): iterable
    {
        foreach (self::refusableEventProvider() as $name => [$eventClass, $responseClass]) {
            yield $name . ': response then reject' => [$eventClass, [
                static fn($event) => $event->setResponse(new $responseClass()),
                static fn($event) => $event->reject(403, 'forbidden'),
            ]];
            yield $name . ': response then disconnect' => [$eventClass, [
                static fn($event) => $event->setResponse(new $responseClass()),
                static fn($event) => $event->disconnect(4500, 'forbidden'),
            ]];
            yield $name . ': reject then response' => [$eventClass, [
                static fn($event) => $event->reject(403, 'forbidden'),
                static fn($event) => $event->setResponse(new $responseClass()),
            ]];
            yield $name . ': disconnect then clearing response' => [$eventClass, [
                static fn($event) => $event->disconnect(4500, 'forbidden'),
                static fn($event) => $event->setResponse(null),
            ]];
            yield $name . ': reject then disconnect' => [$eventClass, [
                static fn($event) => $event->reject(403, 'forbidden'),
                static fn($event) => $event->disconnect(4500, 'forbidden'),
            ]];
        }
    }

    /**
     * @param list<\Closure> $steps
     */
    #[DataProvider('conflictingAnswerProvider')]
    public function testConflictingAnswersThrowLogicException(string $eventClass, array $steps): void
    {
        $event = new $eventClass($this->makeRequest($eventClass));
        [$firstStep, $conflictingStep] = $steps;
        $firstStep($event);

        $this->expectException(\LogicException::class);
        $conflictingStep($event);
    }

    public function testClearedResponseCanBeRejected(): void
    {
        $event = new PublishEvent($this->makeRequest(PublishEvent::class));
        $event->setResponse(new PublishResponse());
        $event->setResponse(null);

        $event->reject(403, 'forbidden');

        self::assertNotNull($event->getRefusal());
    }

    public static function refusalCodeProvider(): iterable
    {
        yield 'error lowest code'          => [static fn($event) => $event->reject(400, 'm'), true];
        yield 'error highest code'         => [static fn($event) => $event->reject(1999, 'm'), true];
        yield 'error below range'          => [static fn($event) => $event->reject(399, 'm'), false];
        yield 'error above range'          => [static fn($event) => $event->reject(2000, 'm'), false];
        yield 'disconnect lowest code'     => [static fn($event) => $event->disconnect(4000, 'm'), true];
        yield 'disconnect highest code'    => [static fn($event) => $event->disconnect(4999, 'm'), true];
        yield 'disconnect below range'     => [static fn($event) => $event->disconnect(3999, 'm'), false];
        yield 'disconnect above range'     => [static fn($event) => $event->disconnect(5000, 'm'), false];
        yield 'disconnect 32-byte reason'  => [static fn($event) => $event->disconnect(4500, str_repeat('r', 32)), true];
        yield 'disconnect 33-byte reason'  => [static fn($event) => $event->disconnect(4500, str_repeat('r', 33)), false];
        yield 'disconnect 17 two-byte chars' => [static fn($event) => $event->disconnect(4500, str_repeat('ř', 17)), false];
    }

    #[DataProvider('refusalCodeProvider')]
    public function testRefusalCodesFollowCentrifugoRanges(\Closure $refuse, bool $isValid): void
    {
        $event = new RPCEvent($this->makeRequest(RPCEvent::class));

        if (!$isValid) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $refuse($event);

        self::assertNotNull($event->getRefusal());
    }

    public function testTemporaryDisconnectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Refusal(RefusalType::Disconnect, 4500, 'banned', true);
    }

    public function testInvalidEventGetResponseAlwaysReturnsNull(): void
    {
        $event = new InvalidEvent(new Invalid(new \RuntimeException('test')));

        self::assertNull($event->getResponse());
    }

    public function testInvalidEventGetRequestReturnsInjectedRequest(): void
    {
        $request = new Invalid(new \RuntimeException('test'));
        $event = new InvalidEvent($request);

        self::assertSame($request, $event->getRequest());
    }

    public function testInvalidEventSetResponseThrowsRuntimeException(): void
    {
        $event = new InvalidEvent(new Invalid(new \RuntimeException('test')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Setting response for invalid request is not supported');
        $event->setResponse(null);
    }
}
