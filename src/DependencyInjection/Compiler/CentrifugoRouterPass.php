<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\EventListener\CentrifugoEventRouter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects services tagged with fluffy_discord.centrifugo_channel_listener and
 * fluffy_discord.centrifugo_rpc_listener, builds a compile-time routing table,
 * and injects it along with a ServiceLocator into CentrifugoEventRouter.
 */
final class CentrifugoRouterPass implements CompilerPassInterface
{
    private const ALLOWED_CHANNEL_EVENTS = [
        ConnectEvent::class,
        PublishEvent::class,
        SubscribeEvent::class,
        SubRefreshEvent::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CentrifugoEventRouter::class)) {
            return;
        }

        $serviceMap = [];
        $channelTable = [];
        $rpcTable = ['exact' => []];

        foreach ($container->findTaggedServiceIds('fluffy_discord.centrifugo_channel_listener') as $serviceId => $tags) {
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $channel  = isset($tag['channel'])  && is_string($tag['channel'])  ? $tag['channel'] : null;
                $event    = isset($tag['event'])    && is_string($tag['event'])    ? $tag['event']   : null;
                $priority = isset($tag['priority']) && is_numeric($tag['priority']) ? (int) $tag['priority'] : 0;
                $method   = isset($tag['method'])   && is_string($tag['method'])   ? $tag['method']  : null;

                if (empty($channel)) {
                    throw new InvalidArgumentException(sprintf(
                        'Service "%s" has a #[AsCentrifugoChannelListener] tag with an empty "channel".',
                        $serviceId,
                    ));
                }

                $method = $this->resolveMethod($container, $serviceId, $method);

                $event = $this->resolveChannelEvent($container, $serviceId, $method, $event);

                $serviceMap[$serviceId] = new Reference($serviceId);
                $channelTable[$event] ??= ['exact' => [], 'wildcard' => []];

                if (str_contains($channel, '*')) {
                    $regex = '~^' . str_replace('\*', '.*', preg_quote($channel, '~')) . '$~';
                    $channelTable[$event]['wildcard'][$regex][] = [$serviceId, $method, $priority];
                } else {
                    $channelTable[$event]['exact'][$channel][] = [$serviceId, $method, $priority];
                }
            }
        }

        foreach ($container->findTaggedServiceIds('fluffy_discord.centrifugo_rpc_listener') as $serviceId => $tags) {
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $rpcMethod = isset($tag['rpc_method']) && is_string($tag['rpc_method']) ? $tag['rpc_method'] : null;
                $priority  = isset($tag['priority'])   && is_numeric($tag['priority'])  ? (int) $tag['priority'] : 0;
                $method    = isset($tag['method'])     && is_string($tag['method'])     ? $tag['method'] : null;

                if (empty($rpcMethod)) {
                    throw new InvalidArgumentException(sprintf(
                        'Service "%s" has a #[AsCentrifugoRpcListener] tag with an empty "rpc_method".',
                        $serviceId,
                    ));
                }

                $method = $this->resolveMethod($container, $serviceId, $method);

                $serviceMap[$serviceId] = new Reference($serviceId);
                $rpcTable['exact'][$rpcMethod][] = [$serviceId, $method, $priority];
            }
        }

        foreach ($channelTable as $eventClass => &$buckets) {
            foreach ($buckets['exact'] as &$handlers) {
                usort($handlers, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
            }
            unset($handlers);
            foreach ($buckets['wildcard'] as &$handlers) {
                usort($handlers, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
            }
            unset($handlers);
        }
        unset($buckets);

        foreach ($rpcTable['exact'] as &$handlers) {
            usort($handlers, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
        }
        unset($handlers);

        $routingTable = [
            'channels' => $channelTable,
            'rpc'      => $rpcTable,
        ];

        $locatorRef = ServiceLocatorTagPass::register($container, $serviceMap);

        $container->getDefinition(CentrifugoEventRouter::class)
            ->replaceArgument(0, $locatorRef)
            ->replaceArgument(1, $routingTable)
        ;
    }

    private function resolveMethod(ContainerBuilder $container, string $serviceId, ?string $method): string
    {
        $method ??= '__invoke';

        $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
        if (!method_exists($class, $method)) {
            throw new InvalidArgumentException(sprintf(
                'Service "%s" (class "%s") does not have a method "%s".',
                $serviceId,
                $class,
                $method,
            ));
        }

        return $method;
    }

    private function resolveChannelEvent(ContainerBuilder $container, string $serviceId, string $method, ?string $event): string
    {
        if ($event === null) {
            $class      = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
            $reflection = $container->getReflectionClass($class);
            if ($reflection === null) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot reflect class for service "%s" to infer the event type. '
                    . 'Specify "event" explicitly on #[AsCentrifugoChannelListener].',
                    $serviceId,
                ));
            }

            $reflectionMethod = $reflection->getMethod($method);
            $params           = $reflectionMethod->getParameters();
            if ($params === [] || !($type = $params[0]->getType()) instanceof \ReflectionNamedType) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot infer event type for service "%s::%s()". '
                    . 'Specify "event" explicitly on #[AsCentrifugoChannelListener].',
                    $class,
                    $method,
                ));
            }

            $event = $type->getName();
        }

        if (!in_array($event, self::ALLOWED_CHANNEL_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Service "%s" has a #[AsCentrifugoChannelListener] with unsupported event "%s". '
                . 'Allowed events: %s.',
                $serviceId,
                $event,
                implode(', ', self::ALLOWED_CHANNEL_EVENTS),
            ));
        }

        return $event;
    }
}
