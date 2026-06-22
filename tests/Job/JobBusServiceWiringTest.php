<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\RoadRunner\Jobs\JobsInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * IT-B1/IT-B2 — the Jobs message bus is wired onto Symfony Messenger by config/services.php (the typed
 * bus is registered because symfony/messenger is installed in the dev env), JobsInterface stays
 * ungated, and jobs.bus repoints the consumer at a named bus.
 */
class JobBusServiceWiringTest extends BaseTestCase
{
    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        return $container;
    }

    public function testBusServicesAreRegistered(): void
    {
        $container = $this->loadServices();

        self::assertTrue($container->hasDefinition(JobsInterface::class), 'JobsInterface must stay ungated');
        self::assertTrue($container->hasDefinition(JobDispatcher::class));
        self::assertTrue($container->getDefinition(JobDispatcher::class)->isPublic());
        self::assertTrue($container->hasDefinition(JobRoutingListener::class));
    }

    public function testRoutingListenerDispatchesIntoDefaultBus(): void
    {
        $container = $this->loadServices();

        $arg0 = $container->getDefinition(JobRoutingListener::class)->getArgument(0);

        self::assertInstanceOf(Reference::class, $arg0);
        self::assertSame(MessageBusInterface::class, (string) $arg0);
    }

    public function testRoutingListenerIsTaggedOnJobsRunEvent(): void
    {
        $container = $this->loadServices();

        $tags = $container->getDefinition(JobRoutingListener::class)->getTag('kernel.event_listener');

        $found = false;
        foreach ($tags as $tag) {
            if (($tag['event'] ?? null) === JobsRunEvent::class && ($tag['method'] ?? null) === 'onJobsRun') {
                $found = true;
                self::assertSame(-100, $tag['priority'] ?? null);
            }
        }

        self::assertTrue($found, 'JobRoutingListener must listen to JobsRunEvent::onJobsRun');
    }

    public function testJobsBusConfigRepointsTheListener(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        // temporal/sdk is installed in the test env, so load() resolves the Temporal frontend
        // address; point it at the bundled .rr.yaml fixture so the build succeeds.
        $container->setParameter('kernel.project_dir', __DIR__ . '/../Temporal/Fixtures');

        (new FluffyDiscordRoadRunnerExtension())->load(
            [[
                'rr_config_path' => 'temporal.rr.yaml',
                'kv' => ['auto_register' => false],
                'jobs' => ['bus' => 'app.custom_bus'],
            ]],
            $container,
        );

        $arg0 = $container->getDefinition(JobRoutingListener::class)->getArgument(0);

        self::assertInstanceOf(Reference::class, $arg0);
        self::assertSame('app.custom_bus', (string) $arg0);
    }
}
