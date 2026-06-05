<?php

namespace FluffyDiscord\RoadRunnerBundle\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @phpstan-type CentrifugoHandler array{0: string, 1: string, 2: int}
 * @phpstan-type CentrifugoChannelBucket array{exact: array<string, list<CentrifugoHandler>>, wildcard: array<string, list<CentrifugoHandler>>}
 * @phpstan-type CentrifugoRoutingTable array{channels: array<string, CentrifugoChannelBucket>, rpc: array{exact: array<string, list<CentrifugoHandler>>}}
 */
final class CentrifugoEventRouter
{
    /** @var array<string, list<CentrifugoHandler>> */
    private array $resolvedCache = [];

    /**
     * @param ServiceLocator<object>  $locator
     * @param CentrifugoRoutingTable  $routingTable
     */
    public function __construct(
        private readonly ServiceLocator $locator,
        private readonly array $routingTable,
    ) {
    }

    public function onConnect(ConnectEvent $event): void
    {
        $seen     = [];
        $handlers = [];

        foreach ($event->getRequest()->channels as $channel) {
            foreach ($this->resolveChannelHandlers(ConnectEvent::class, $channel) as $handler) {
                $key = $handler[0] . '::' . $handler[1];
                if (!isset($seen[$key])) {
                    $seen[$key]  = true;
                    $handlers[]  = $handler;
                }
            }
        }

        if ($handlers !== []) {
            usort($handlers, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
            $this->invoke($handlers, $event);
        }
    }

    public function onPublish(PublishEvent $event): void
    {
        $this->invoke(
            $this->resolveChannelHandlers(PublishEvent::class, $event->getRequest()->channel),
            $event,
        );
    }

    public function onSubscribe(SubscribeEvent $event): void
    {
        $this->invoke(
            $this->resolveChannelHandlers(SubscribeEvent::class, $event->getRequest()->channel),
            $event,
        );
    }

    public function onSubRefresh(SubRefreshEvent $event): void
    {
        $this->invoke(
            $this->resolveChannelHandlers(SubRefreshEvent::class, $event->getRequest()->channel),
            $event,
        );
    }

    public function onRpc(RPCEvent $event): void
    {
        $rpcMethod = $event->getRequest()->method;
        if ($rpcMethod === null) {
            return;
        }

        $this->invoke(
            $this->routingTable['rpc']['exact'][$rpcMethod] ?? [],
            $event,
        );
    }

    /**
     * @return list<CentrifugoHandler>
     */
    private function resolveChannelHandlers(string $eventClass, string $channel): array
    {
        $cacheKey = $eventClass . ':' . $channel;
        if (array_key_exists($cacheKey, $this->resolvedCache)) {
            return $this->resolvedCache[$cacheKey];
        }

        $table    = $this->routingTable['channels'][$eventClass] ?? [];
        $handlers = $table['exact'][$channel] ?? [];

        foreach ($table['wildcard'] ?? [] as $regex => $wildcardHandlers) {
            if (preg_match($regex, $channel)) {
                foreach ($wildcardHandlers as $h) {
                    $handlers[] = $h;
                }
            }
        }

        if ($handlers !== [] && ($table['wildcard'] ?? []) !== []) {
            usort($handlers, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
        }

        return $this->resolvedCache[$cacheKey] = $handlers;
    }

    public function resetCache(): void
    {
        $this->resolvedCache = [];
    }

    /**
     * @param list<CentrifugoHandler> $handlers
     */
    private function invoke(array $handlers, Event $event): void
    {
        foreach ($handlers as [$serviceId, $method]) {
            if ($event->isPropagationStopped()) {
                return;
            }

            ($this->locator->get($serviceId))->$method($event);
        }
    }
}
