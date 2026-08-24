<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoChannelListener;
use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoRpcListener;
use FluffyDiscord\RoadRunnerBundle\Cache\KVCacheAdapter;
use FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcSecurityFacts;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcInvoker;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAccessTokenAuthenticator;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAuthorizationGuard;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface;
use FluffyDiscord\RoadRunnerBundle\Grpc\Tracing\GrpcTracingListener;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcProfilerSubscriber;
use FluffyDiscord\RoadRunnerBundle\Doctrine\DoctrinePreconnectListener;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\CacheAutoRegisterException;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Exception\TemporalAddressException;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\IgbinaryJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\SymfonyJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\StartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Tracing\TemporalTracingListener;
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
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface as GrpcServiceInterface;
use Spiral\RoadRunner\KeyValue\Cache;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;
use Sentry\State\HubInterface as SentryHubInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Workflow\WorkflowInterface;

class FluffyDiscordRoadRunnerExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('monolog')) {
            return;
        }

        if (class_exists(WorkflowInterface::class)) {
            $container->prependExtensionConfig('monolog', [
                'channels' => ['temporal'],
            ]);
        }

        if (interface_exists(GrpcServiceInterface::class)) {
            $container->prependExtensionConfig('monolog', [
                'channels' => ['grpc'],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . "/../../config"));
        $loader->load("services.php");

        if ($container->getParameter('kernel.debug')) {
            $loader->load("debug.php");
        }

        if (class_exists(RoadRunnerCentrifugoWorker::class)) {
            $container->registerAttributeForAutoconfiguration(
                AsCentrifugoChannelListener::class,
                static function (ChildDefinition $definition, AsCentrifugoChannelListener $attr, \Reflector $refl): void {
                    $tag = [
                        'channel'  => $attr->channel,
                        'event'    => $attr->event,
                        'priority' => $attr->priority,
                        'method'   => $attr->method,
                    ];
                    if ($refl instanceof \ReflectionMethod) {
                        $tag['method'] = $refl->getName();
                        if ($tag['event'] === null) {
                            $params = $refl->getParameters();
                            if ($params !== [] && ($type = $params[0]->getType()) instanceof \ReflectionNamedType) {
                                $tag['event'] = $type->getName();
                            }
                        }
                    }
                    $definition->addTag('fluffy_discord.centrifugo_channel_listener', $tag);
                },
            );

            $container->registerAttributeForAutoconfiguration(
                AsCentrifugoRpcListener::class,
                static function (ChildDefinition $definition, AsCentrifugoRpcListener $attr, \Reflector $refl): void {
                    $tag = [
                        'rpc_method' => $attr->rpcMethod,
                        'priority'   => $attr->priority,
                        'method'     => $attr->method,
                    ];
                    if ($refl instanceof \ReflectionMethod) {
                        $tag['method'] = $refl->getName();
                    }
                    $definition->addTag('fluffy_discord.centrifugo_rpc_listener', $tag);
                },
            );
        }

        $configuration = $this->getConfiguration([], $container);
        /** @var array{http: array{lazy_boot: bool, request_factory: 'auto'|'native'|'psr7'}, warmup: array{enabled: bool, learn: bool, learn_requests: int, manifest_path: ?string}, centrifugo: array{lazy_boot: bool}, jobs: array{lazy_boot: bool, serializer: 'native'|'igbinary'|'symfony'|null, default_queue: non-empty-string, bus: ?string}, doctrine: array{preconnect: bool}, kv: array{auto_register: bool, serializer: ?string, keypair_path: ?string}, rr_config_path: ?string, temporal?: array{namespace?: string, tracing?: bool, api_key?: ?string, retryable_errors?: list<string>, default_worker_options?: array<string, mixed>, worker_options?: array<string, array<string, mixed>>}, grpc?: array{tracing: bool, profiler: array{redacted_metadata_keys: list<string>}, security: array{enabled: bool, token_handler: ?string, metadata_key: string, token_prefix: string, required: bool, firewall_name: string, user_provider: ?string}}} $config */
        $config = $this->processConfiguration($configuration, $configs);

        if ($container->hasDefinition(HttpWorker::class)) {
            $definition = $container->getDefinition(HttpWorker::class);
            $definition->replaceArgument(0, $config["http"]["lazy_boot"]);
        }

        $container->setParameter("fluffy_discord.http.request_factory", $config["http"]["request_factory"]);

        if ($config["warmup"]["enabled"] === true) {
            $this->registerWarmup($container, $config["warmup"]);
        }

        if ($container->hasDefinition(CentrifugoWorker::class)) {
            $definition = $container->getDefinition(CentrifugoWorker::class);
            $definition->replaceArgument(0, $config["centrifugo"]["lazy_boot"]);
        }

        if ($container->hasDefinition(JobsWorker::class)) {
            $definition = $container->getDefinition(JobsWorker::class);
            $definition->replaceArgument(0, $config["jobs"]["lazy_boot"]);
        }

        if ($container->hasDefinition(JobDispatcher::class)) {
            $container->getDefinition(JobDispatcher::class)
                ->replaceArgument(2, $config["jobs"]["default_queue"]);
        }

        $bus = $config["jobs"]["bus"];
        if (is_string($bus) && $bus !== '' && $container->hasDefinition(JobRoutingListener::class)) {
            $container->getDefinition(JobRoutingListener::class)
                ->replaceArgument(0, new Reference($bus));
        }

        if ($container->hasAlias(JobSerializerInterface::class)) {
            $serializer = $config["jobs"]["serializer"]
                ?? (function_exists('igbinary_serialize') ? 'igbinary' : 'native');

            $serializerClass = match ($serializer) {
                'symfony'  => SymfonyJobSerializer::class,
                'igbinary' => IgbinaryJobSerializer::class,
                default    => NativeJobSerializer::class,
            };
            $container->setAlias(JobSerializerInterface::class, $serializerClass);
        }

        $this->setTemporalParameters($config, $container);

        if (class_exists(WorkflowInterface::class) && ($config['temporal']['tracing'] ?? false) === true) {
            $this->registerTemporalTracing($container);
        }

        if (interface_exists(GrpcServiceInterface::class)) {
            $this->registerGrpc($config['grpc'] ?? null, $config['rr_config_path'], $container);
        }

        if (class_exists(\Doctrine\DBAL\Connection::class) && $config["doctrine"]["preconnect"] === true) {
            $this->registerDoctrinePreconnect($container);
        }

        if (class_exists(Cache::class) && $config["kv"]["auto_register"] === true) {
            $rrConfig = $this->getRoadRunnerConfig($container, $config);

            /** @var array<string, mixed> $kvConfig */
            $kvConfig = $rrConfig["kv"] ?? [];
            foreach (array_keys($kvConfig) as $name) {
                $container
                    ->register("cache.adapter.rr_kv.{$name}", KVCacheAdapter::class)
                    ->setFactory([KVCacheAdapter::class, "create"])
                    ->setArguments([
                        "",
                        $container->getDefinition(RPCInterface::class),
                        $name,
                        $container->getParameter("kernel.project_dir"),
                        $config["kv"]["serializer"],
                        $config["kv"]["keypair_path"],
                    ])
                ;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readRoadRunnerYaml(ContainerBuilder $container, ?string $rrConfigPath): array
    {
        if ($rrConfigPath === null) {
            return [];
        }

        /** @var string $projectDir */
        $projectDir = $container->getParameter("kernel.project_dir");

        return new RoadRunnerYamlConfigReader($projectDir, $rrConfigPath)->readAll();
    }

    /**
     * @param array{rr_config_path: ?string} $config
     * @return array<string, mixed>
     */
    private function getRoadRunnerConfig(ContainerBuilder $container, array $config): array
    {
        try {
            $rpc = $container->get(RPCInterface::class);
            assert($rpc instanceof RPCInterface);

            /** @var string $rpcResult */
            $rpcResult = $rpc->call("rpc.Config", null);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(base64_decode($rpcResult), true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (\JsonException $jsonException) {
            throw new CacheAutoRegisterException($jsonException->getMessage(), previous: $jsonException);
        } catch (InvalidRPCConfigurationException | RelayException $roadRunnerUnavailable) {
            $yaml = $this->readRoadRunnerYaml($container, $config["rr_config_path"]);
            if ($yaml !== []) {
                return $yaml;
            }

            if ($config["rr_config_path"] !== null) {
                /** @var string $projectDir */
                $projectDir = $container->getParameter("kernel.project_dir");
                throw new CacheAutoRegisterException(
                    sprintf('Unable to read RoadRunner config: %s', $projectDir . "/" . $config["rr_config_path"]),
                    previous: $roadRunnerUnavailable,
                );
            }

            throw new CacheAutoRegisterException('Error connecting to RPC service. Is RoadRunner running? Optionally set "rr_config_path" in bundle\'s config.', previous: $roadRunnerUnavailable);
        }
    }

    /**
     * @param array{rr_config_path: ?string, temporal?: array{namespace?: string, tracing?: bool, api_key?: ?string, retryable_errors?: list<string>, default_worker_options?: array<string, mixed>, worker_options?: array<string, array<string, mixed>>}} $config
     */
    private function setTemporalParameters(array $config, ContainerBuilder $container): void
    {
        $temporal = $config['temporal'] ?? null;
        if ($temporal === null) {
            return;
        }

        $container->setParameter('fluffy_discord.roadrunner.temporal.namespace', $temporal['namespace'] ?? 'default');
        $container->setParameter('fluffy_discord.roadrunner.temporal.api_key', $temporal['api_key'] ?? null);
        $container->setParameter('fluffy_discord.roadrunner.temporal.retryable_errors', $temporal['retryable_errors'] ?? [\Error::class]);
        $container->setParameter('fluffy_discord.roadrunner.temporal.default_worker_options', $temporal['default_worker_options'] ?? []);
        $container->setParameter('fluffy_discord.roadrunner.temporal.worker_options', $temporal['worker_options'] ?? []);

        if ($container->hasDefinition(ServiceClientInterface::class)) {
            $container->setParameter('fluffy_discord.roadrunner.temporal.address', $this->resolveTemporalAddress($config, $container));
        }
    }

    /**
     * @param array{rr_config_path: ?string} $config
     */
    private function resolveTemporalAddress(array $config, ContainerBuilder $container): string
    {
        try {
            $rrConfig = $this->getRoadRunnerConfig($container, $config);
        } catch (CacheAutoRegisterException $cacheAutoRegisterException) {
            throw new TemporalAddressException(
                'Unable to resolve the Temporal frontend address from RoadRunner: ' . $cacheAutoRegisterException->getMessage(),
                previous: $cacheAutoRegisterException,
            );
        }

        $temporal = $rrConfig['temporal'] ?? null;
        if (is_array($temporal) && isset($temporal['address']) && is_string($temporal['address']) && $temporal['address'] !== '') {
            return $temporal['address'];
        }

        throw new TemporalAddressException(
            'RoadRunner config has no non-empty "temporal.address". Enable the "temporal" plugin with an "address" in your RoadRunner config (.rr.yaml).',
        );
    }

    /**
     * @param array{tracing: bool, profiler: array{redacted_metadata_keys: list<string>}, security: array{enabled: bool, token_handler: ?string, metadata_key: string, token_prefix: string, required: bool, firewall_name: string, user_provider: ?string}}|null $grpcConfig
     */
    private function registerGrpc(?array $grpcConfig, ?string $rrConfigPath, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(GrpcServiceInterface::class)
            ->addTag('fluffy_discord.roadrunner.grpc.service');

        if ($grpcConfig === null) {
            return;
        }

        $securityConfig = $grpcConfig['security'];
        $redactedMetadataKeys = $grpcConfig['profiler']['redacted_metadata_keys'];

        if ($securityConfig['enabled']) {
            $redactedMetadataKeys[] = $securityConfig['metadata_key'];
        }

        if ($container->hasDefinition(RoadRunnerYamlConfigReader::class)) {
            $container->getDefinition(RoadRunnerYamlConfigReader::class)->replaceArgument(1, $rrConfigPath);
        }

        if ($container->hasDefinition(GrpcIntrospector::class)) {
            $securityFacts = new Definition(GrpcSecurityFacts::class, [
                $securityConfig['enabled'],
                $securityConfig['token_handler'],
                $securityConfig['metadata_key'],
                $securityConfig['required'],
            ]);
            $container->getDefinition(GrpcIntrospector::class)->replaceArgument(2, $securityFacts);
        }

        if ($container->hasDefinition(GrpcProfilerSubscriber::class)) {
            $container->getDefinition(GrpcProfilerSubscriber::class)->replaceArgument(6, array_values(array_unique(array_map('strtolower', $redactedMetadataKeys))));
        }

        if ($grpcConfig['tracing'] === true) {
            $this->registerGrpcTracing($container);
        }

        if ($securityConfig['enabled']) {
            $this->registerGrpcSecurity($securityConfig, $container);
        }
    }

    private function registerGrpcTracing(ContainerBuilder $container): void
    {
        $definition = new Definition(GrpcTracingListener::class, [
            new Reference('monolog.logger.grpc', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(SentryHubInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $definition->addTag('kernel.event_listener', ['event' => GrpcCallReceivedEvent::class, 'method' => 'onCallReceived']);
        $definition->addTag('kernel.event_listener', ['event' => GrpcCallCompletedEvent::class, 'method' => 'onCallCompleted']);
        $definition->addTag('kernel.event_listener', ['event' => GrpcCallFailedEvent::class, 'method' => 'onCallFailed']);

        $container->setDefinition(GrpcTracingListener::class, $definition);
    }

    /**
     * @param array{enabled: bool, token_handler: ?string, metadata_key: string, token_prefix: string, required: bool, firewall_name: string, user_provider: ?string} $securityConfig
     */
    private function registerGrpcSecurity(array $securityConfig, ContainerBuilder $container): void
    {
        $registeredBundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        $securityBundleRegistered = is_array($registeredBundles) && isset($registeredBundles['SecurityBundle']);
        $securityUsable = $securityBundleRegistered && interface_exists('Symfony\\Component\\Security\\Http\\AccessToken\\AccessTokenHandlerInterface');

        if (!$securityUsable) {
            throw new InvalidConfigurationException('fluffy_discord_road_runner.grpc.security.enabled requires symfony/security-bundle and symfony/security-http');
        }

        $tokenHandlerId = $securityConfig['token_handler'];

        if ($tokenHandlerId === null) {
            throw new InvalidConfigurationException('fluffy_discord_road_runner.grpc.security.token_handler is required when grpc.security.enabled is true');
        }

        $userProviderReference = $securityConfig['user_provider'] !== null
            ? new Reference($securityConfig['user_provider'])
            : new Reference('Symfony\\Component\\Security\\Core\\User\\UserProviderInterface', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $authenticator = new Definition(GrpcAccessTokenAuthenticator::class, [
            new Reference($tokenHandlerId),
            new Reference('security.token_storage'),
            $userProviderReference,
            new Reference('Symfony\\Component\\Security\\Core\\User\\UserCheckerInterface', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $securityConfig['metadata_key'],
            $securityConfig['token_prefix'],
            $securityConfig['required'],
            $securityConfig['firewall_name'],
        ]);
        $container->setDefinition(GrpcAccessTokenAuthenticator::class, $authenticator);
        $container->setAlias(GrpcCallAuthenticatorInterface::class, GrpcAccessTokenAuthenticator::class);

        $guard = new Definition(GrpcAuthorizationGuard::class, [
            new Reference('security.authorization_checker'),
            new Reference('security.token_storage'),
        ]);
        $container->setDefinition(GrpcAuthorizationGuard::class, $guard);
    }

    private function registerTemporalTracing(ContainerBuilder $container): void
    {
        $definition = new Definition(TemporalTracingListener::class, [
            new Reference('monolog.logger.temporal', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(SentryHubInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $definition->addTag('kernel.event_listener', ['event' => StartEvent::class, 'method' => 'onWorkflowStart']);
        $definition->addTag('kernel.event_listener', ['event' => ExecuteActivityEvent::class, 'method' => 'onExecuteActivity']);
        $definition->addTag('kernel.event_listener', ['event' => ActivityEvent::class, 'method' => 'onActivityInbound']);

        $container->setDefinition(TemporalTracingListener::class, $definition);
    }

    /**
     * See docs/specs/worker-warmup.md. All wiring lives here (config-flag-gated), not in
     * config/services.php — same rule as registerDoctrinePreconnect.
     *
     * @param array{enabled: bool, learn: bool, learn_requests: int, manifest_path: ?string} $warmupConfig
     */
    private function registerWarmup(ContainerBuilder $container, array $warmupConfig): void
    {
        $container
            ->registerForAutoconfiguration(WorkerWarmerInterface::class)
            ->addTag('fluffy_discord.road_runner.worker_warmer');

        $manifestPath = $warmupConfig['manifest_path'] ?? '%kernel.cache_dir%/roadrunner/warmup.manifest.json';

        $storage = new Definition(WarmupManifestStorage::class, [
            $manifestPath,
            new Reference('parameter_bag', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $container->setDefinition(WarmupManifestStorage::class, $storage);

        $learnedManifestWarmer = new Definition(LearnedManifestWarmer::class, [
            new Reference(WarmupManifestStorage::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $learnedManifestWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 64]);
        $container->setDefinition(LearnedManifestWarmer::class, $learnedManifestWarmer);

        $containerPreloadWarmer = new Definition(ContainerPreloadWarmer::class, [
            '%kernel.build_dir%',
            new Reference(WarmupManifestStorage::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $containerPreloadWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 48]);
        $container->setDefinition(ContainerPreloadWarmer::class, $containerPreloadWarmer);

        $routerWarmer = new Definition(RouterWarmer::class, [
            new Reference('router.default', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $routerWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 32]);
        $container->setDefinition(RouterWarmer::class, $routerWarmer);

        if (interface_exists(\Doctrine\Persistence\ManagerRegistry::class)) {
            $doctrineWarmer = new Definition(DoctrineWarmer::class, [
                new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
            $doctrineWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 32]);
            $container->setDefinition(DoctrineWarmer::class, $doctrineWarmer);
        }

        $eventListenersWarmer = new Definition(EventListenersWarmer::class, [
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $eventListenersWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 16]);
        $container->setDefinition(EventListenersWarmer::class, $eventListenersWarmer);

        if (interface_exists(\Symfony\Component\Form\FormRegistryInterface::class)) {
            $formRegistryWarmer = new Definition(FormRegistryWarmer::class, [
                new TaggedIteratorArgument('form.type'),
                new Reference('form.registry', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
            $formRegistryWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 16]);
            $container->setDefinition(FormRegistryWarmer::class, $formRegistryWarmer);
        }

        $twigRuntimesWarmer = new Definition(TwigRuntimesWarmer::class, [
            new TaggedIteratorArgument('twig.runtime'),
        ]);
        $twigRuntimesWarmer->addTag('fluffy_discord.road_runner.worker_warmer', ['priority' => 16]);
        $container->setDefinition(TwigRuntimesWarmer::class, $twigRuntimesWarmer);

        $runner = new Definition(WorkerWarmupRunner::class, [
            new TaggedIteratorArgument('fluffy_discord.road_runner.worker_warmer'),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '%kernel.runtime_mode.worker%',
        ]);
        $runner->addTag('kernel.event_listener', ['event' => WorkerBootingEvent::class, 'method' => '__invoke', 'priority' => 128]);
        $container->setDefinition(WorkerWarmupRunner::class, $runner);

        if ($warmupConfig['learn'] === true) {
            $recorder = new Definition(WarmupManifestRecorder::class, [
                new Reference(WarmupManifestStorage::class),
                '%kernel.cache_dir%',
                $warmupConfig['learn_requests'],
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '%kernel.runtime_mode.worker%',
            ]);
            $recorder->addTag('kernel.event_listener', ['event' => WorkerResponseSentEvent::class, 'method' => '__invoke']);
            $container->setDefinition(WarmupManifestRecorder::class, $recorder);
        }
    }

    private function registerDoctrinePreconnect(ContainerBuilder $container): void
    {
        // "doctrine" registry referenced optionally: DBAL without doctrine-bundle → null → no-op.
        $definition = new Definition(DoctrinePreconnectListener::class, [
            new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $definition->addTag('kernel.event_listener', ['event' => WorkerBootingEvent::class, 'method' => '__invoke']);

        $container->setDefinition(DoctrinePreconnectListener::class, $definition);
    }
}
