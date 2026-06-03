<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects services tagged fluffy_discord.job_handler, builds a compile-time message-class → handler
 * routing table, and injects it with a ServiceLocator into JobRoutingListener.
 *
 * @see \FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\CentrifugoRouterPass
 */
final class JobHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(JobRoutingListener::class)) {
            return;
        }

        /** @var array<string, Reference> $serviceMap */
        $serviceMap = [];
        /** @var array<class-string, list<array{0: string, 1: string, 2: int}>> $routingTable */
        $routingTable = [];

        foreach ($container->findTaggedServiceIds('fluffy_discord.job_handler') as $serviceId => $tags) {
            foreach ($tags as $tag) {
                if (!\is_array($tag)) {
                    continue;
                }

                $message = isset($tag['message']) && \is_string($tag['message']) ? $tag['message'] : null;
                $priority = isset($tag['priority']) && \is_numeric($tag['priority']) ? (int) $tag['priority'] : 0;
                $method = isset($tag['method']) && \is_string($tag['method']) ? $tag['method'] : null;

                $method = $this->resolveMethod($container, $serviceId, $method);
                $message = $this->resolveMessage($container, $serviceId, $method, $message);

                $serviceMap[$serviceId] = new Reference($serviceId);
                $routingTable[$message][] = [$serviceId, $method, $priority];
            }
        }

        foreach ($routingTable as &$handlers) {
            \usort($handlers, static fn (array $a, array $b): int => $b[2] <=> $a[2]);
        }
        unset($handlers);

        $locatorRef = ServiceLocatorTagPass::register($container, $serviceMap);

        $container->getDefinition(JobRoutingListener::class)
            ->replaceArgument(0, $locatorRef)
            ->replaceArgument(1, $routingTable)
        ;
    }

    private function resolveMethod(ContainerBuilder $container, string $serviceId, ?string $method): string
    {
        $method ??= '__invoke';

        $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
        if (!\method_exists($class, $method)) {
            throw new InvalidArgumentException(\sprintf(
                'Service "%s" (class "%s") does not have a method "%s".',
                $serviceId,
                $class,
                $method,
            ));
        }

        return $method;
    }

    /**
     * @return class-string
     */
    private function resolveMessage(ContainerBuilder $container, string $serviceId, string $method, ?string $message): string
    {
        if ($message === null) {
            $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
            $reflection = $container->getReflectionClass($class);
            if ($reflection === null) {
                throw new InvalidArgumentException(\sprintf(
                    'Cannot reflect class for service "%s" to infer the job message type. '
                    . 'Specify "message" explicitly on #[AsJobHandler].',
                    $serviceId,
                ));
            }

            $params = $reflection->getMethod($method)->getParameters();
            if ($params === [] || !($type = $params[0]->getType()) instanceof \ReflectionNamedType) {
                throw new InvalidArgumentException(\sprintf(
                    'Cannot infer the job message type for "%s::%s()". '
                    . 'Specify "message" explicitly on #[AsJobHandler].',
                    $class,
                    $method,
                ));
            }

            $message = $type->getName();
        }

        if (!\class_exists($message)) {
            throw new InvalidArgumentException(\sprintf(
                'Service "%s" has a #[AsJobHandler] for message "%s", which is not a loadable class.',
                $serviceId,
                $message,
            ));
        }

        return $message;
    }
}
