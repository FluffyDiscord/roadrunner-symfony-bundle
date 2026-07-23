<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Warmup\ContainerPreloadWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\DoctrineWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\EventListenersWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\FormRegistryWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\LearnedManifestWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\RouterWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\TwigRuntimesWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestRecorder;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestStorage;
use FluffyDiscord\RoadRunnerBundle\Warmup\WorkerWarmerInterface;
use FluffyDiscord\RoadRunnerBundle\Warmup\WorkerWarmupRunner;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** See docs/specs/worker-warmup.md §9 (W1-W6). */
class WarmupWiringTest extends BaseTestCase
{
    private const string WARMER_TAG = 'fluffy_discord.road_runner.worker_warmer';

    /** @param array<string, mixed>|null $warmup */
    private function load(?array $warmup): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/../Temporal/Fixtures');

        $config = [
            'rr_config_path' => 'temporal.rr.yaml',
            'kv'             => ['auto_register' => false],
        ];
        if ($warmup !== null) {
            $config['warmup'] = $warmup;
        }

        (new FluffyDiscordRoadRunnerExtension())->load([$config], $container);

        return $container;
    }

    public function testDefaultConfigWiresRunnerAndWarmers(): void
    {
        $container = $this->load(null);

        $runnerTags = $container->getDefinition(WorkerWarmupRunner::class)->getTag('kernel.event_listener');
        self::assertSame(WorkerBootingEvent::class, $runnerTags[0]['event']);
        self::assertSame(128, $runnerTags[0]['priority']);

        $expectedPriorities = [
            LearnedManifestWarmer::class => 64,
            ContainerPreloadWarmer::class => 48,
            RouterWarmer::class => 32,
            DoctrineWarmer::class => 32,
            EventListenersWarmer::class => 16,
            FormRegistryWarmer::class => 16,
            TwigRuntimesWarmer::class => 16,
        ];
        foreach ($expectedPriorities as $warmerClass => $priority) {
            self::assertTrue($container->hasDefinition($warmerClass), $warmerClass);
            $tags = $container->getDefinition($warmerClass)->getTag(self::WARMER_TAG);
            self::assertSame($priority, $tags[0]['priority'], $warmerClass);
        }

        self::assertTrue($container->hasDefinition(WarmupManifestStorage::class));

        $routerReference = $container->getDefinition(RouterWarmer::class)->getArgument(0);
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Reference::class, $routerReference);
        self::assertSame('router.default', (string) $routerReference, 'must bypass router-alias decorators (e.g. Sylius LocaleStrippingRouter)');
    }

    public function testEnabledFalseWiresNothing(): void
    {
        $container = $this->load(['enabled' => false]);

        self::assertFalse($container->hasDefinition(WorkerWarmupRunner::class));
        self::assertFalse($container->hasDefinition(WarmupManifestStorage::class));
        self::assertFalse($container->hasDefinition(LearnedManifestWarmer::class));
        self::assertFalse($container->hasDefinition(WarmupManifestRecorder::class));
        self::assertArrayNotHasKey(WorkerWarmerInterface::class, $container->getAutoconfiguredInstanceof());
    }

    public function testLearnFalseDropsRecorderKeepsWarmers(): void
    {
        $container = $this->load(['learn' => false]);

        self::assertFalse($container->hasDefinition(WarmupManifestRecorder::class));
        self::assertTrue($container->hasDefinition(WorkerWarmupRunner::class));
        self::assertTrue($container->hasDefinition(LearnedManifestWarmer::class));
    }

    public function testRecorderListensOnResponseSent(): void
    {
        $container = $this->load(null);

        $tags = $container->getDefinition(WarmupManifestRecorder::class)->getTag('kernel.event_listener');
        self::assertSame(WorkerResponseSentEvent::class, $tags[0]['event']);
    }

    public function testAutoconfigurationTagsCustomWarmer(): void
    {
        $container = $this->load(null);

        $autoconfigured = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(WorkerWarmerInterface::class, $autoconfigured);
        self::assertArrayHasKey(self::WARMER_TAG, $autoconfigured[WorkerWarmerInterface::class]->getTags());
    }

    public function testHttpWorkerSignatureUpdated(): void
    {
        $container = $this->load(null);

        $arguments = $container->getDefinition(\FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::class)->getArguments();
        $constructorParameters = new \ReflectionClass(\FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::class)
            ->getConstructor()
            ?->getParameters() ?? [];

        self::assertCount(count($constructorParameters), $arguments, 'services.php args must match the ctor after the early_router_initialization removal');
        self::assertFalse($arguments[0], 'argument 0 is lazy_boot (replaced from config, default false)');
    }

    public function testManifestPathConfigurable(): void
    {
        $container = $this->load(['manifest_path' => '/var/data/warmup.json']);

        self::assertSame('/var/data/warmup.json', $container->getDefinition(WarmupManifestStorage::class)->getArgument(0));
    }
}
