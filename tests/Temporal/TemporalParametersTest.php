<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\TemporalWorkerPass;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospectorInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Worker\ServiceCredentials;

/**
 * TC-05/06/07 — the A1/A2 wiring of the refactor: the config -> container-parameter flow the autowired
 * Temporal services consume via param(), the dedicated compiler-pass registration, and the
 * introspector-interface alias.
 */
class TemporalParametersTest extends BaseTestCase
{
    /**
     * @param array<string, mixed> $temporal
     */
    private function load(array $temporal): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');

        // No RoadRunner running: the address resolves from the bundled .rr.yaml fixture.
        (new FluffyDiscordRoadRunnerExtension())->load(
            [[
                'rr_config_path' => 'temporal.rr.yaml',
                'kv' => ['auto_register' => false],
                'temporal' => $temporal,
            ]],
            $container,
        );

        return $container;
    }

    public function testConfigFlowsIntoContainerParameters(): void
    {
        $container = $this->load([
            'namespace'              => 'my_ns',
            'api_key'                => 'secret',
            'retryable_errors'       => [\LogicException::class],
            'default_worker_options' => ['maxConcurrentActivityExecutionSize' => 7],
            'worker_options'         => ['billing' => ['maxConcurrentActivityExecutionSize' => 3]],
        ]);

        self::assertSame('my_ns', $container->getParameter('fluffy_discord.roadrunner.temporal.namespace'));
        self::assertSame('secret', $container->getParameter('fluffy_discord.roadrunner.temporal.api_key'));
        self::assertSame([\LogicException::class], $container->getParameter('fluffy_discord.roadrunner.temporal.retryable_errors'));
        self::assertSame(['maxConcurrentActivityExecutionSize' => 7], $container->getParameter('fluffy_discord.roadrunner.temporal.default_worker_options'));
        self::assertSame(['billing' => ['maxConcurrentActivityExecutionSize' => 3]], $container->getParameter('fluffy_discord.roadrunner.temporal.worker_options'));
        self::assertSame('127.0.0.1:7233', $container->getParameter('fluffy_discord.roadrunner.temporal.address'));

        // The autowired clients must reference those parameters, not literal values — guards against a
        // dropped param() reference re-introducing the old hardcoded-then-overwritten wiring.
        self::assertSame('%fluffy_discord.roadrunner.temporal.address%', (string) $container->getDefinition(ServiceClientInterface::class)->getArgument(0));
        self::assertSame('%fluffy_discord.roadrunner.temporal.api_key%', (string) $container->getDefinition(ServiceClientInterface::class)->getArgument(1));
        self::assertSame('%fluffy_discord.roadrunner.temporal.namespace%', (string) $container->getDefinition(ClientOptions::class)->getArgument(0));
        self::assertSame('%fluffy_discord.roadrunner.temporal.api_key%', (string) $container->getDefinition(ServiceCredentials::class)->getArgument(0));
        self::assertSame('%fluffy_discord.roadrunner.temporal.retryable_errors%', (string) $container->getDefinition(ExceptionInterceptor::class)->getArgument(0));
        self::assertSame('%fluffy_discord.roadrunner.temporal.default_worker_options%', (string) $container->getDefinition(DefaultTemporalWorker::class)->getArgument(1));
    }

    public function testDefaultsAreSetWhenTemporalNodeEmpty(): void
    {
        $container = $this->load([]);

        self::assertSame('default', $container->getParameter('fluffy_discord.roadrunner.temporal.namespace'));
        self::assertNull($container->getParameter('fluffy_discord.roadrunner.temporal.api_key'));
        self::assertSame([\Error::class], $container->getParameter('fluffy_discord.roadrunner.temporal.retryable_errors'));
        self::assertSame([], $container->getParameter('fluffy_discord.roadrunner.temporal.default_worker_options'));
        self::assertSame([], $container->getParameter('fluffy_discord.roadrunner.temporal.worker_options'));
    }

    public function testIntrospectorInterfaceAliasResolves(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasAlias(TemporalIntrospectorInterface::class));
        self::assertSame(TemporalIntrospector::class, (string) $container->getAlias(TemporalIntrospectorInterface::class));
    }

    public function testExtensionIsNotACompilerPassAndBundleRegistersTheTemporalWorkerPass(): void
    {
        self::assertNotInstanceOf(CompilerPassInterface::class, new FluffyDiscordRoadRunnerExtension());

        $container = new ContainerBuilder();
        (new FluffyDiscordRoadRunnerBundle())->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $registered = array_filter($passes, static fn (object $pass): bool => $pass instanceof TemporalWorkerPass);

        self::assertCount(1, $registered);
    }
}
