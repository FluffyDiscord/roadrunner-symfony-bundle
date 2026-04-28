<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Event\Centrifugo;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefreshEvent;
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
