<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\JobRoutingListenerPass;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * IT-B3 — messenger is optional (composer suggest: "Without it, only the raw JobsRunEvent is
 * available"), so an absent message bus must drop the routing listener instead of breaking the build.
 */
class JobRoutingListenerPassTest extends BaseTestCase
{
    private function makeContainer(string $busId): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $listener = new Definition(JobRoutingListener::class, [
            new Reference($busId),
            [],
            null,
        ]);
        $container->setDefinition(JobRoutingListener::class, $listener);

        return $container;
    }

    public function testTheListenerSurvivesWhenTheBusExists(): void
    {
        $container = $this->makeContainer(MessageBusInterface::class);
        $container->register(MessageBusInterface::class, \stdClass::class);

        new JobRoutingListenerPass()->process($container);

        self::assertTrue($container->hasDefinition(JobRoutingListener::class));
    }

    public function testTheListenerIsDroppedWhenMessengerIsNotEnabled(): void
    {
        $container = $this->makeContainer(MessageBusInterface::class);

        new JobRoutingListenerPass()->process($container);

        self::assertFalse($container->hasDefinition(JobRoutingListener::class));
    }

    public function testAnExplicitlyConfiguredBusThatIsMissingIsRejected(): void
    {
        $container = $this->makeContainer('app.command_bus');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/jobs\.bus "app\.command_bus"/');

        new JobRoutingListenerPass()->process($container);
    }

    private function loadRealServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $loader = new \Symfony\Component\DependencyInjection\Loader\PhpFileLoader($container, new \Symfony\Component\Config\FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        return $container;
    }

    public function testTheShippedConfigKeepsTheListenerWhenABusIsRegistered(): void
    {
        $container = $this->loadRealServices();
        $container->register(MessageBusInterface::class, \stdClass::class);

        new JobRoutingListenerPass()->process($container);

        self::assertTrue($container->hasDefinition(JobRoutingListener::class));
    }

    public function testTheShippedConfigDropsTheListenerWhenMessengerIsNotEnabled(): void
    {
        $container = $this->loadRealServices();
        self::assertTrue($container->hasDefinition(JobRoutingListener::class), 'config/services.php must register the listener for this test to mean anything');

        new JobRoutingListenerPass()->process($container);

        self::assertFalse($container->hasDefinition(JobRoutingListener::class));
    }

    public function testThePassIsInertWithoutTheListener(): void
    {
        $container = new ContainerBuilder();

        new JobRoutingListenerPass()->process($container);

        self::assertFalse($container->hasDefinition(JobRoutingListener::class));
    }
}
