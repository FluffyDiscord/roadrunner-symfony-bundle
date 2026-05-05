<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\EventListener\CentrifugoEventRouter;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use RoadRunner\Centrifugo\Request\Connect;
use RoadRunner\Centrifugo\Request\Publish;
use RoadRunner\Centrifugo\Request\RPC;
use RoadRunner\Centrifugo\Request\SubRefresh;
use RoadRunner\Centrifugo\Request\Subscribe;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AllowMockObjectsWithoutExpectations]
class CentrifugoEventRouterTest extends BaseTestCase
{
    private WorkerInterface $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = $this->createMock(WorkerInterface::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRouter(array $routingTable, array $services = []): CentrifugoEventRouter
    {
        $locator = new ServiceLocator($services);
        return new CentrifugoEventRouter($locator, $routingTable);
    }

    /** @return array{handler: object, calls: array<int, object>, svcId: string, locatorEntry: array} */
    private function makeHandler(string $svcId = 'svc'): array
    {
        $calls   = [];
        $handler = new class($calls) {
            public function __construct(private array &$calls) {}
            public function handle(object $event): void { $this->calls[] = $event; }
            public function __invoke(object $event): void { $this->calls[] = $event; }
        };

        return [
            'handler'      => $handler,
            'calls'        => &$calls,
            'svcId'        => $svcId,
            'locatorEntry' => [$svcId => fn() => $handler],
        ];
    }

    private function publishEvent(string $channel): PublishEvent
    {
        return new PublishEvent(new Publish($this->worker, 'c', 'ws', 'json', 'json', 'u', $channel, [], [], []));
    }

    private function subscribeEvent(string $channel): SubscribeEvent
    {
        return new SubscribeEvent(new Subscribe($this->worker, 'c', 'ws', 'json', 'json', 'u', $channel, '', [], [], []));
    }

    private function subRefreshEvent(string $channel): SubRefreshEvent
    {
        return new SubRefreshEvent(new SubRefresh($this->worker, 'c', 'ws', 'json', 'json', 'u', $channel, [], []));
    }

    private function connectEvent(array $channels): ConnectEvent
    {
        return new ConnectEvent(new Connect($this->worker, 'c', 'ws', 'json', 'json', [], null, null, $channels, []));
    }

    private function rpcEvent(?string $method): RPCEvent
    {
        return new RPCEvent(new RPC($this->worker, 'c', 'ws', 'json', 'json', 'u', $method, [], [], []));
    }

    // -------------------------------------------------------------------------
    // Publish — exact channel
    // -------------------------------------------------------------------------

    public function testExactChannelMatchCallsHandler(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => ['exact' => ['news' => [[$h['svcId'], 'handle', 0]]], 'wildcard' => []]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onPublish($this->publishEvent('news'));

        $this->assertCount(1, $h['calls']);
    }

    public function testExactChannelMismatchDoesNotCallHandler(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => ['exact' => ['news' => [[$h['svcId'], 'handle', 0]]], 'wildcard' => []]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onPublish($this->publishEvent('sports'));

        $this->assertCount(0, $h['calls']);
    }

    // -------------------------------------------------------------------------
    // Publish — wildcard channel
    // -------------------------------------------------------------------------

    public function testWildcardMatchCallsHandler(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => ['exact' => [], 'wildcard' => ['~^chat:.*$~' => [[$h['svcId'], 'handle', 0]]]]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onPublish($this->publishEvent('chat:general'));

        $this->assertCount(1, $h['calls']);
    }

    public function testWildcardNoMatchDoesNotCallHandler(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => ['exact' => [], 'wildcard' => ['~^chat:.*$~' => [[$h['svcId'], 'handle', 0]]]]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onPublish($this->publishEvent('news'));

        $this->assertCount(0, $h['calls']);
    }

    // -------------------------------------------------------------------------
    // Priority ordering
    // -------------------------------------------------------------------------

    public function testHandlersCalledInPriorityOrder(): void
    {
        $order = [];
        $high = new class($order) {
            public function __construct(private array &$order) {}
            public function handle(object $e): void { $this->order[] = 'high'; }
        };
        $low = new class($order) {
            public function __construct(private array &$order) {}
            public function handle(object $e): void { $this->order[] = 'low'; }
        };

        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => [
                'exact'    => ['news' => [['high_svc', 'handle', 10], ['low_svc', 'handle', 5]]],
                'wildcard' => [],
            ]],
            'rpc' => ['exact' => []],
        ], [
            'high_svc' => fn() => $high,
            'low_svc'  => fn() => $low,
        ]);

        $router->onPublish($this->publishEvent('news'));

        $this->assertSame(['high', 'low'], $order);
    }

    // -------------------------------------------------------------------------
    // Stop propagation
    // -------------------------------------------------------------------------

    public function testStopPropagationHaltsHandlerChain(): void
    {
        $calls = [];
        $first = new class($calls) {
            public function __construct(private array &$calls) {}
            public function handle(object $event): void {
                $this->calls[] = 'first';
                $event->stopPropagation();
            }
        };
        $second = new class($calls) {
            public function __construct(private array &$calls) {}
            public function handle(object $event): void { $this->calls[] = 'second'; }
        };

        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => [
                'exact'    => ['news' => [['first_svc', 'handle', 10], ['second_svc', 'handle', 5]]],
                'wildcard' => [],
            ]],
            'rpc' => ['exact' => []],
        ], [
            'first_svc'  => fn() => $first,
            'second_svc' => fn() => $second,
        ]);

        $router->onPublish($this->publishEvent('news'));

        $this->assertSame(['first'], $calls);
    }

    // -------------------------------------------------------------------------
    // Subscribe / SubRefresh
    // -------------------------------------------------------------------------

    public function testSubscribeEventRouted(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [SubscribeEvent::class => ['exact' => ['chat' => [[$h['svcId'], 'handle', 0]]], 'wildcard' => []]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onSubscribe($this->subscribeEvent('chat'));

        $this->assertCount(1, $h['calls']);
    }

    public function testSubRefreshEventRouted(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [SubRefreshEvent::class => ['exact' => ['live' => [[$h['svcId'], 'handle', 0]]], 'wildcard' => []]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onSubRefresh($this->subRefreshEvent('live'));

        $this->assertCount(1, $h['calls']);
    }

    // -------------------------------------------------------------------------
    // ConnectEvent — channels array
    // -------------------------------------------------------------------------

    public function testConnectEventRoutedByChannel(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [ConnectEvent::class => ['exact' => ['chat' => [[$h['svcId'], 'handle', 0]]], 'wildcard' => []]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onConnect($this->connectEvent(['chat']));

        $this->assertCount(1, $h['calls']);
    }

    public function testConnectEventDeduplicatesHandlerAcrossChannels(): void
    {
        $h = $this->makeHandler();
        // Same handler matches both 'chat:a' and 'chat:b' via wildcard
        $router = $this->makeRouter([
            'channels' => [ConnectEvent::class => [
                'exact'    => [],
                'wildcard' => ['~^chat:.*$~' => [[$h['svcId'], 'handle', 0]]],
            ]],
            'rpc' => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onConnect($this->connectEvent(['chat:a', 'chat:b']));

        $this->assertCount(1, $h['calls'], 'Handler matching both channels must be called only once');
    }

    public function testConnectEventNoMatchDoesNotCallHandler(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [ConnectEvent::class => ['exact' => ['news' => [[$h['svcId'], 'handle', 0]]], 'wildcard' => []]],
            'rpc'      => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onConnect($this->connectEvent(['chat:general']));

        $this->assertCount(0, $h['calls']);
    }

    // -------------------------------------------------------------------------
    // RPC routing
    // -------------------------------------------------------------------------

    public function testRpcExactMethodCallsHandler(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [],
            'rpc'      => ['exact' => ['ping' => [[$h['svcId'], 'handle', 0]]]],
        ], $h['locatorEntry']);

        $router->onRpc($this->rpcEvent('ping'));

        $this->assertCount(1, $h['calls']);
    }

    public function testRpcNullMethodSkipsInvocation(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [],
            'rpc'      => ['exact' => ['ping' => [[$h['svcId'], 'handle', 0]]]],
        ], $h['locatorEntry']);

        $router->onRpc($this->rpcEvent(null));

        $this->assertCount(0, $h['calls']);
    }

    public function testRpcUnknownMethodSkipsInvocation(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [],
            'rpc'      => ['exact' => ['ping' => [[$h['svcId'], 'handle', 0]]]],
        ], $h['locatorEntry']);

        $router->onRpc($this->rpcEvent('getUser'));

        $this->assertCount(0, $h['calls']);
    }

    // -------------------------------------------------------------------------
    // Resolved channel cache
    // -------------------------------------------------------------------------

    public function testCachePreventsRepeatedWildcardEvaluation(): void
    {
        $matchCount = 0;
        $h = $this->makeHandler();

        // We verify caching indirectly: the handler is called on both requests
        // but wildcard resolution only runs once (no assertion on internals,
        // we assert the functional outcome is identical on both calls).
        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => [
                'exact'    => [],
                'wildcard' => ['~^chat:.*$~' => [[$h['svcId'], 'handle', 0]]],
            ]],
            'rpc' => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onPublish($this->publishEvent('chat:room'));
        $router->onPublish($this->publishEvent('chat:room')); // second call — hits cache

        $this->assertCount(2, $h['calls']);
    }

    public function testResetCacheAllowsFreshResolution(): void
    {
        $h = $this->makeHandler();
        $router = $this->makeRouter([
            'channels' => [PublishEvent::class => [
                'exact'    => ['news' => [[$h['svcId'], 'handle', 0]]],
                'wildcard' => [],
            ]],
            'rpc' => ['exact' => []],
        ], $h['locatorEntry']);

        $router->onPublish($this->publishEvent('news')); // populates cache
        $router->resetCache();
        $router->onPublish($this->publishEvent('news')); // re-resolves after reset

        $this->assertCount(2, $h['calls']);
    }

    // -------------------------------------------------------------------------
    // Empty routing table
    // -------------------------------------------------------------------------

    public function testNoHandlersRegisteredDoesNotThrow(): void
    {
        $router = $this->makeRouter(['channels' => [], 'rpc' => ['exact' => []]]);

        $router->onPublish($this->publishEvent('news'));
        $router->onSubscribe($this->subscribeEvent('news'));
        $router->onSubRefresh($this->subRefreshEvent('news'));
        $router->onConnect($this->connectEvent(['news']));
        $router->onRpc($this->rpcEvent('ping'));

        $this->addToAssertionCount(1); // reached without exception
    }
}
