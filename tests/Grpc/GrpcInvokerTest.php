<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcHandlerFaultException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcInvoker;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMethodRoute;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcRoutingTable;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRoute;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** TC-05 / TC-06 / TC-07 */
class GrpcInvokerTest extends BaseTestCase
{
    /** @var list<object> */
    private array $dispatchedEvents = [];

    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatchedEvents = [];
        $this->eventDispatcher = new EventDispatcher();

        foreach ([GrpcCallReceivedEvent::class, GrpcCallCompletedEvent::class, GrpcCallFailedEvent::class] as $eventClass) {
            $this->eventDispatcher->addListener($eventClass, function (object $event): void {
                $this->dispatchedEvents[] = $event;
            });
        }
    }

    private function buildEchoRoute(): GrpcServiceRoute
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator(['app.echo' => static fn (): EchoService => new EchoService()]));
        $registry->addService(EchoInterface::class, 'app.echo', EchoService::class);
        $route = GrpcRoutingTable::fromRegistry($registry)->getRoute('bundle.test.Echo');
        self::assertNotNull($route);

        return $route;
    }

    public function testHappyPathDispatchesReceivedThenCompletedWithDecodedMessages(): void
    {
        $route = $this->buildEchoRoute();
        $invoker = new GrpcInvoker($this->eventDispatcher);
        $requestBody = new PingRequest()->setMessage('hi')->serializeToString();

        $responseBody = $invoker->invoke($route, $route->methods['Ping'], new Context([]), $requestBody);

        $response = new PingResponse();
        $response->mergeFromString($responseBody);
        self::assertSame('hi', $response->getMessage());

        self::assertCount(2, $this->dispatchedEvents);
        $received = $this->dispatchedEvents[0];
        $completed = $this->dispatchedEvents[1];
        self::assertInstanceOf(GrpcCallReceivedEvent::class, $received);
        self::assertInstanceOf(GrpcCallCompletedEvent::class, $completed);
        self::assertSame('bundle.test.Echo', $received->serviceName);
        self::assertInstanceOf(PingRequest::class, $received->request);
        self::assertSame('hi', $received->request->getMessage());
        self::assertInstanceOf(PingResponse::class, $completed->response);
        self::assertGreaterThan(0.0, $completed->durationMs);
    }

    public function testEmptyInputYieldsAnEmptyMessage(): void
    {
        $route = $this->buildEchoRoute();
        $invoker = new GrpcInvoker($this->eventDispatcher);

        $responseBody = $invoker->invoke($route, $route->methods['Ping'], new Context([]), '');

        $response = new PingResponse();
        $response->mergeFromString($responseBody);
        self::assertSame('', $response->getMessage());
    }

    public function testGrpcExceptionDispatchesExactlyOneFailedEventAndRethrows(): void
    {
        $route = $this->buildEchoRoute();
        $invoker = new GrpcInvoker($this->eventDispatcher);

        try {
            $invoker->invoke($route, $route->methods['Fail'], new Context([]), '');
            self::fail('expected GRPCException');
        } catch (GRPCException $expected) {
        }

        $failedEvents = array_values(array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof GrpcCallFailedEvent));
        self::assertCount(1, $failedEvents);
        self::assertSame(StatusCode::INVALID_ARGUMENT, $failedEvents[0]->workerStatusCode);
        self::assertNotNull($failedEvents[0]->request);
    }

    public function testUnhandledThrowableClassifiesAsUnknown(): void
    {
        $route = $this->buildEchoRoute();
        $invoker = new GrpcInvoker($this->eventDispatcher);

        try {
            $invoker->invoke($route, $route->methods['Crash'], new Context([]), '');
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $expected) {
        }

        $failedEvents = array_values(array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof GrpcCallFailedEvent));
        self::assertCount(1, $failedEvents);
        self::assertSame(StatusCode::UNKNOWN, $failedEvents[0]->workerStatusCode);
    }

    public function testWrongReturnTypeIsAHandlerFault(): void
    {
        $liarService = new class implements ServiceInterface {
            public function Ping(Context $ctx, PingRequest $in): PingRequest
            {
                return $in;
            }
        };
        $pingMethod = Method::parse(new \ReflectionMethod(EchoInterface::class, 'Ping'));
        $route = new GrpcServiceRoute('bundle.test.Echo', EchoInterface::class, $liarService, []);
        $invoker = new GrpcInvoker($this->eventDispatcher);

        $this->expectException(GrpcHandlerFaultException::class);
        $this->expectExceptionMessageMatches('/must return/');

        $invoker->invoke($route, new GrpcMethodRoute($pingMethod, []), new Context([]), '');
    }

    public function testCompletedListenerThrowablePropagatesWithoutAFailedEvent(): void
    {
        $route = $this->buildEchoRoute();
        $this->eventDispatcher->addListener(GrpcCallCompletedEvent::class, static function (): void {
            throw new \DomainException('listener exploded');
        });
        $invoker = new GrpcInvoker($this->eventDispatcher);

        try {
            $invoker->invoke($route, $route->methods['Ping'], new Context([]), '');
            self::fail('expected DomainException');
        } catch (\DomainException $expected) {
        }

        $failedEvents = array_filter($this->dispatchedEvents, static fn (object $event): bool => $event instanceof GrpcCallFailedEvent);
        self::assertCount(0, $failedEvents);
    }
}
