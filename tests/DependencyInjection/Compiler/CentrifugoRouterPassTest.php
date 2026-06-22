<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\CentrifugoRouterPass;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\EventListener\CentrifugoEventRouter;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

class CentrifugoRouterPassTest extends BaseTestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new ContainerBuilder();

        // Register the router with abstract args so replaceArgument works
        $this->container->register(CentrifugoEventRouter::class, CentrifugoEventRouter::class)
            ->addArgument(null) // locator placeholder
            ->addArgument(null) // routing table placeholder
        ;
    }

    private function runPass(): void
    {
        (new CentrifugoRouterPass())->process($this->container);
    }

    private function routingTable(): array
    {
        return $this->container->getDefinition(CentrifugoEventRouter::class)->getArgument(1);
    }

    public function testSkipsProcessingWhenRouterNotRegistered(): void
    {
        $container = new ContainerBuilder(); // no CentrifugoEventRouter
        (new CentrifugoRouterPass())->process($container);

        $this->assertFalse($container->hasDefinition(CentrifugoEventRouter::class));
    }

    public function testExactChannelRegisteredInTable(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'news', PublishEvent::class, 'handle');

        $this->runPass();

        $table = $this->routingTable();
        $this->assertArrayHasKey('news', $table['channels'][PublishEvent::class]['exact']);
        $this->assertSame(
            [[PublishHandlerFixture::class, 'handle', 0]],
            $table['channels'][PublishEvent::class]['exact']['news'],
        );
    }

    public function testMultipleExactChannelsForSameEvent(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'news', PublishEvent::class, 'handle');
        $this->registerChannelListener(SubscribeHandlerFixture::class, 'sports', PublishEvent::class, 'handle', 5);

        $this->runPass();

        $exact = $this->routingTable()['channels'][PublishEvent::class]['exact'];
        $this->assertArrayHasKey('news', $exact);
        $this->assertArrayHasKey('sports', $exact);
    }

    public function testWildcardChannelCompiledToRegex(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'chat:*', PublishEvent::class, 'handle');

        $this->runPass();

        $wildcard = $this->routingTable()['channels'][PublishEvent::class]['wildcard'];
        $this->assertCount(1, $wildcard);

        $regex = array_key_first($wildcard);
        $this->assertMatchesRegularExpression($regex, 'chat:general');
        $this->assertMatchesRegularExpression($regex, 'chat:room-42');
        $this->assertDoesNotMatchRegularExpression($regex, 'other:channel');
    }

    public function testBareWildcardMatchesAnything(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, '*', PublishEvent::class, 'handle');

        $this->runPass();

        $wildcard = $this->routingTable()['channels'][PublishEvent::class]['wildcard'];
        $regex = array_key_first($wildcard);
        $this->assertMatchesRegularExpression($regex, 'anything');
        $this->assertMatchesRegularExpression($regex, 'chat:room');
    }

    public function testWildcardNotPutInExactBucket(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'chat:*', PublishEvent::class, 'handle');

        $this->runPass();

        $table = $this->routingTable()['channels'][PublishEvent::class];
        $this->assertEmpty($table['exact']);
        $this->assertCount(1, $table['wildcard']);
    }

    public function testHandlersSortedByPriorityDescending(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'news', PublishEvent::class, 'handle', 5);
        $this->registerChannelListener(SubscribeHandlerFixture::class, 'news', PublishEvent::class, 'handle', 10);

        $this->runPass();

        $handlers = $this->routingTable()['channels'][PublishEvent::class]['exact']['news'];
        $this->assertSame(10, $handlers[0][2]);
        $this->assertSame(5, $handlers[1][2]);
    }

    public function testWildcardHandlersSortedByPriorityDescending(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'chat:*', PublishEvent::class, 'handle', 1);
        $this->registerChannelListener(SubscribeHandlerFixture::class, 'chat:*', PublishEvent::class, 'handle', 99);

        $this->runPass();

        $wildcard = $this->routingTable()['channels'][PublishEvent::class]['wildcard'];
        $handlers = reset($wildcard);
        $this->assertSame(99, $handlers[0][2]);
        $this->assertSame(1, $handlers[1][2]);
    }

    public function testEventInferredFromMethodTypeHint(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'news', null, 'handle');

        $this->runPass();

        $table = $this->routingTable();
        $this->assertArrayHasKey(PublishEvent::class, $table['channels']);
    }

    public function testInvokeMethodIsDefaultWhenMethodNull(): void
    {
        $this->registerChannelListener(InvokableHandlerFixture::class, 'news', SubscribeEvent::class, null);

        $this->runPass();

        $handlers = $this->routingTable()['channels'][SubscribeEvent::class]['exact']['news'];
        $this->assertSame('__invoke', $handlers[0][1]);
    }

    public function testConnectEventRegistered(): void
    {
        $this->registerChannelListener(ConnectHandlerFixture::class, 'chat', ConnectEvent::class, 'handle');
        $this->runPass();
        $this->assertArrayHasKey(ConnectEvent::class, $this->routingTable()['channels']);
    }

    public function testSubscribeEventRegistered(): void
    {
        $this->registerChannelListener(SubscribeHandlerFixture::class, 'sports', SubscribeEvent::class, 'handle');
        $this->runPass();
        $this->assertArrayHasKey(SubscribeEvent::class, $this->routingTable()['channels']);
    }

    public function testSubRefreshEventRegistered(): void
    {
        $this->registerChannelListener(SubRefreshHandlerFixture::class, 'live', SubRefreshEvent::class, 'handle');
        $this->runPass();
        $this->assertArrayHasKey(SubRefreshEvent::class, $this->routingTable()['channels']);
    }

    public function testRpcListenerRegisteredInTable(): void
    {
        $this->registerRpcListener(RpcHandlerFixture::class, 'ping', 'handle');

        $this->runPass();

        $table = $this->routingTable();
        $this->assertArrayHasKey('ping', $table['rpc']['exact']);
        $this->assertSame(
            [[RpcHandlerFixture::class, 'handle', 0]],
            $table['rpc']['exact']['ping'],
        );
    }

    public function testRpcHandlersSortedByPriorityDescending(): void
    {
        $this->registerRpcListener(RpcHandlerFixture::class, 'ping', 'handle', 5);
        $this->registerRpcListener(PublishHandlerFixture::class, 'ping', 'handle', 20);

        $this->runPass();

        $handlers = $this->routingTable()['rpc']['exact']['ping'];
        $this->assertSame(20, $handlers[0][2]);
        $this->assertSame(5, $handlers[1][2]);
    }

    public function testEmptyChannelThrowsInvalidArgumentException(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, '', PublishEvent::class, 'handle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty "channel"/');
        $this->runPass();
    }

    public function testEmptyRpcMethodThrowsInvalidArgumentException(): void
    {
        $this->registerRpcListener(RpcHandlerFixture::class, '', 'handle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty "rpc_method"/');
        $this->runPass();
    }

    public function testUnsupportedEventClassThrowsInvalidArgumentException(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'news', \stdClass::class, 'handle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported event/i');
        $this->runPass();
    }

    public function testNonExistentMethodThrowsInvalidArgumentException(): void
    {
        $this->registerChannelListener(PublishHandlerFixture::class, 'news', PublishEvent::class, 'nonExistentMethod');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not have a method/');
        $this->runPass();
    }

    public function testUnresolvableEventTypeThrowsInvalidArgumentException(): void
    {
        // NoTypeHintFixture::__invoke has no typed parameter → event cannot be inferred
        $this->registerChannelListener(NoTypeHintFixture::class, 'news', null, '__invoke');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot infer event type/');
        $this->runPass();
    }

    private function registerChannelListener(
        string  $class,
        string  $channel,
        ?string $event,
        ?string $method,
        int     $priority = 0,
    ): void {
        $this->container->register($class, $class)
            ->addTag('fluffy_discord.centrifugo_channel_listener', [
                'channel'  => $channel,
                'event'    => $event,
                'method'   => $method,
                'priority' => $priority,
            ]);
    }

    private function registerRpcListener(
        string  $class,
        string  $rpcMethod,
        ?string $method,
        int     $priority = 0,
    ): void {
        $this->container->register($class, $class)
            ->addTag('fluffy_discord.centrifugo_rpc_listener', [
                'rpc_method' => $rpcMethod,
                'method'     => $method,
                'priority'   => $priority,
            ]);
    }
}

class PublishHandlerFixture
{
    public function handle(PublishEvent $event): void {}
}

class SubscribeHandlerFixture
{
    public function handle(PublishEvent $event): void {}
}

class ConnectHandlerFixture
{
    public function handle(ConnectEvent $event): void {}
}

class SubRefreshHandlerFixture
{
    public function handle(SubRefreshEvent $event): void {}
}

class InvokableHandlerFixture
{
    public function __invoke(SubscribeEvent $event): void {}
}

class RpcHandlerFixture
{
    public function handle(\FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent $event): void {}
}

class NoTypeHintFixture
{
    public function __invoke($event): void {}
}
