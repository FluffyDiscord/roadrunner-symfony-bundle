<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefusableEvent;
use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use RoadRunner\Centrifugal\Proxy\DTO\V1 as DTO;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Payload;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

/**
 * @see \FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker
 */
#[AllowMockObjectsWithoutExpectations]
class CentrifugoWorkerRefusalTest extends AbstractCentrifugoWorkerTestCase
{
    /** @var list<string> */
    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->frames = [];
        $this->goridgeWorker->method('respond')->willReturnCallback(function (Payload $payload): void {
            $this->frames[] = $payload->body;
        });
    }

    public static function refusalProvider(): iterable
    {
        $reject = static fn(RefusableEvent $event) => $event->reject(1000, 'slow down', true);
        $disconnect = static fn(RefusableEvent $event) => $event->disconnect(4501, 'banned');

        foreach (self::refusableRequestProvider() as $name => [$makeRequest, $responseDtoClass]) {
            yield $name . ' rejected' => [$makeRequest, $responseDtoClass, $reject, ['error', 1000, 'slow down', true]];
            yield $name . ' disconnected' => [$makeRequest, $responseDtoClass, $disconnect, ['disconnect', 4501, 'banned']];
        }
    }

    public static function refusableRequestProvider(): iterable
    {
        yield 'connect'   => ['makeConnect', DTO\ConnectResponse::class];
        yield 'publish'   => ['makePublish', DTO\PublishResponse::class];
        yield 'subscribe' => ['makeSubscribe', DTO\SubscribeResponse::class];
        yield 'rpc'       => ['makeRpc', DTO\RPCResponse::class];
    }

    /**
     * @param class-string<Message> $responseDtoClass
     * @param list<mixed>           $expectedFrame
     */
    #[DataProvider('refusalProvider')]
    public function testRefusalSendsExactlyOneRefusalFrame(string $makeRequest, string $responseDtoClass, \Closure $refuse, array $expectedFrame): void
    {
        $this->answerWith($refuse);

        $this->makeWorker(requests: [$this->{$makeRequest}()])->start();

        $this->assertCount(1, $this->frames);
        $this->assertSame($expectedFrame, $this->describeFrame($this->frames[0], $responseDtoClass));
    }

    public static function defaultDenyProvider(): iterable
    {
        yield 'connect'     => ['makeConnect', DTO\ConnectResponse::class, ['disconnect', 4500, 'forbidden']];
        yield 'publish'     => ['makePublish', DTO\PublishResponse::class, ['error', 403, 'forbidden', false]];
        yield 'subscribe'   => ['makeSubscribe', DTO\SubscribeResponse::class, ['error', 403, 'forbidden', false]];
        yield 'rpc'         => ['makeRpc', DTO\RPCResponse::class, ['error', 403, 'forbidden', false]];
        yield 'refresh'     => ['makeRefresh', DTO\RefreshResponse::class, ['expired', true]];
        yield 'sub_refresh' => ['makeSubRefresh', DTO\SubRefreshResponse::class, ['expired', true]];
    }

    /**
     * @param class-string<Message> $responseDtoClass
     * @param list<mixed>           $expectedFrame
     */
    #[DataProvider('defaultDenyProvider')]
    public function testUnansweredRequestIsDeniedByDefault(string $makeRequest, string $responseDtoClass, array $expectedFrame): void
    {
        $this->answerWith(static fn(CentrifugoEventInterface $event) => null);

        $worker = $this->makeWorker(requests: [$this->{$makeRequest}()]);
        $worker->start();

        $this->assertCount(1, $this->frames);
        $this->assertSame($expectedFrame, $this->describeFrame($this->frames[0], $responseDtoClass));
        $this->assertSame([], $worker->loggedErrors);
    }

    public function testResponseIsSentAsResult(): void
    {
        $this->answerWith(static fn(CentrifugoEventInterface $event) => $event->setResponse(new ConnectResponse(user: '42')));

        $this->makeWorker(requests: [$this->makeConnect()])->start();

        $this->assertCount(1, $this->frames);
        $this->assertSame(['result'], $this->describeFrame($this->frames[0], DTO\ConnectResponse::class));
    }

    public static function failureHandlingProvider(): iterable
    {
        yield 'refusal is ordinary control flow' => [static fn(RefusableEvent $event) => $event->reject(1000, 'slow down'), 0];
        yield 'exception still takes the failure path' => [static fn(RefusableEvent $event) => throw new \RuntimeException('boom'), 1];
    }

    #[DataProvider('failureHandlingProvider')]
    public function testOnlyExceptionsReachSentryLogsAndReboot(\Closure $listener, int $expectedFailures): void
    {
        $kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, RebootableInterface::class]);
        $kernel->expects($this->exactly($expectedFailures))->method('reboot');
        $this->kernel = $kernel;

        $sentryHub = $this->createMock(SentryHubInterface::class);
        $sentryHub->expects($this->exactly($expectedFailures))->method('captureException');

        $this->answerWith($listener);

        $worker = $this->makeWorker(requests: [$this->makeRpc()], sentryHub: $sentryHub);
        $worker->start();

        $this->assertCount(1, $this->frames);
        $this->assertCount($expectedFailures, $worker->loggedErrors);
    }

    public function testRpcRejectedAfterClearingResponseSendsOnlyTheRefusal(): void
    {
        $this->answerWith(static function (RefusableEvent $event): void {
            $event->setResponse(new RPCResponse(data: ['pong' => true]));
            $event->setResponse(null);
            $event->reject(429, 'too many requests');
        });

        $this->makeWorker(requests: [$this->makeRpc()])->start();

        $this->assertCount(1, $this->frames);
        $this->assertSame(['error', 429, 'too many requests', false], $this->describeFrame($this->frames[0], DTO\RPCResponse::class));
    }

    private function answerWith(\Closure $listener): void
    {
        $this->eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use ($listener): object {
            if ($event instanceof CentrifugoEventInterface) {
                $listener($event);
            }

            return $event;
        });
    }

    /**
     * @param class-string<Message> $responseDtoClass
     *
     * @return list<mixed>
     */
    private function describeFrame(string $frame, string $responseDtoClass): array
    {
        $response = new $responseDtoClass();
        $response->mergeFromString($frame);

        $error = $response->getError();
        $disconnect = $response->getDisconnect();
        $result = $response->getResult();

        if ($error !== null) {
            return ['error', $error->getCode(), $error->getMessage(), $error->getTemporary()];
        }

        if ($disconnect !== null) {
            return ['disconnect', $disconnect->getCode(), $disconnect->getReason()];
        }

        $isExpiringResult = $result instanceof DTO\RefreshResult || $result instanceof DTO\SubRefreshResult;

        if ($isExpiringResult) {
            return ['expired', $result->getExpired()];
        }

        return ['result'];
    }
}
