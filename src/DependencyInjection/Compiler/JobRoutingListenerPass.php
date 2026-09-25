<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

class JobRoutingListenerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $listenerRegistered = $container->hasDefinition(JobRoutingListener::class);

        if (!$listenerRegistered) {
            return;
        }

        $busReference = $container->getDefinition(JobRoutingListener::class)->getArgument(0);

        if (!$busReference instanceof Reference) {
            return;
        }

        $busId = (string)$busReference;
        $busExists = $container->has($busId);

        if ($busExists) {
            return;
        }

        $busWasConfigured = $busId !== MessageBusInterface::class;

        if ($busWasConfigured) {
            throw new InvalidConfigurationException(sprintf('fluffy_discord_road_runner.jobs.bus "%s" is not a registered service', $busId));
        }

        $container->log($this, sprintf('Removed JobRoutingListener: "%s" is not registered, so only the raw JobsRunEvent path is available. Enable framework.messenger to consume #[AsJob] messages.', $busId));
        $container->removeDefinition(JobRoutingListener::class);
    }
}
