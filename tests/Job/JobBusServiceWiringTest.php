<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\DependencyInjection\Compiler\JobHandlerPass;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmail;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmailHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * IT-04 — the Jobs message-bus services are wired by config/services.php, the JobHandlerPass builds a
 * routing table from a tagged handler, and the JobsRunEvent listener is registered.
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

        self::assertTrue($container->hasDefinition(JobDispatcher::class));
        self::assertTrue($container->getDefinition(JobDispatcher::class)->isPublic());
        self::assertTrue($container->hasAlias(JobSerializerInterface::class));
        self::assertTrue($container->hasDefinition(JobRoutingListener::class));
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

    public function testHandlerPassBuildsRoutingTable(): void
    {
        $container = $this->loadServices();

        $container->register(SendWelcomeEmailHandler::class, SendWelcomeEmailHandler::class)
            ->setPublic(true)
            ->addTag('fluffy_discord.job_handler', ['message' => SendWelcomeEmail::class, 'priority' => 0, 'method' => '__invoke']);

        (new JobHandlerPass())->process($container);

        /** @var array<class-string, list<array{0: string, 1: string, 2: int}>> $table */
        $table = $container->getDefinition(JobRoutingListener::class)->getArgument(1);

        self::assertArrayHasKey(SendWelcomeEmail::class, $table);
        self::assertSame([[SendWelcomeEmailHandler::class, '__invoke', 0]], $table[SendWelcomeEmail::class]);
    }
}
