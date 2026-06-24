<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Doctrine;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Configuration;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Doctrine\DoctrinePreconnectListener;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/** See docs/specs/doctrine-preconnect.md §9. */
class DoctrinePreconnectWiringTest extends BaseTestCase
{
    /** @param array<string, mixed>|null $doctrine */
    private function load(?array $doctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        // temporal/sdk is installed, so load() resolves the Temporal address — point project_dir
        // at the bundled .rr.yaml fixture so it succeeds (same workaround as the Job wiring tests).
        $container->setParameter('kernel.project_dir', __DIR__ . '/../Temporal/Fixtures');

        $config = [
            'rr_config_path' => 'temporal.rr.yaml',
            'kv'             => ['auto_register' => false],
        ];
        if ($doctrine !== null) {
            $config['doctrine'] = $doctrine;
        }

        (new FluffyDiscordRoadRunnerExtension())->load([$config], $container);

        return $container;
    }

    public function testListenerRegisteredAndTaggedByDefault(): void
    {
        $container = $this->load(null);

        self::assertTrue($container->hasDefinition(DoctrinePreconnectListener::class));

        $tags = $container->getDefinition(DoctrinePreconnectListener::class)->getTag('kernel.event_listener');
        self::assertCount(1, $tags);
        self::assertSame(WorkerBootingEvent::class, $tags[0]['event']);
        self::assertSame('__invoke', $tags[0]['method']);
    }

    public function testListenerNotRegisteredWhenDisabled(): void
    {
        $container = $this->load(['preconnect' => false]);

        self::assertFalse($container->hasDefinition(DoctrinePreconnectListener::class));
    }

    public function testRegistryAndLoggerAreOptionalReferences(): void
    {
        $container = $this->load(['preconnect' => true]);

        $arguments = $container->getDefinition(DoctrinePreconnectListener::class)->getArguments();

        self::assertInstanceOf(Reference::class, $arguments[0]);
        self::assertSame('doctrine', (string) $arguments[0]);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $arguments[0]->getInvalidBehavior());

        self::assertInstanceOf(Reference::class, $arguments[1]);
        self::assertSame('logger', (string) $arguments[1]);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $arguments[1]->getInvalidBehavior());
    }

    public function testPreconnectDefaultsToTrue(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertTrue($config['doctrine']['preconnect']);
    }
}
