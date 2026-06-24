<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoChannelListener;
use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoRpcListener;
use FluffyDiscord\RoadRunnerBundle\Cache\KVCacheAdapter;
use FluffyDiscord\RoadRunnerBundle\Doctrine\DoctrinePreconnectListener;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
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
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\KeyValue\Cache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;
use Sentry\State\HubInterface as SentryHubInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Workflow\WorkflowInterface;

class FluffyDiscordRoadRunnerExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!class_exists(WorkflowInterface::class) || !$container->hasExtension('monolog')) {
            return;
        }

        $container->prependExtensionConfig('monolog', [
            'channels' => ['temporal'],
        ]);
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
        /** @var array{http: array{early_router_initialization: bool, lazy_boot: bool}, centrifugo: array{lazy_boot: bool}, jobs: array{lazy_boot: bool, serializer: 'native'|'igbinary'|'symfony'|null, default_queue: non-empty-string, bus: ?string}, doctrine: array{preconnect: bool}, kv: array{auto_register: bool, serializer: ?string, keypair_path: ?string}, rr_config_path: ?string, temporal?: array{namespace?: string, tracing?: bool, api_key?: ?string, retryable_errors?: list<string>, default_worker_options?: array<string, mixed>, worker_options?: array<string, array<string, mixed>>}} $config */
        $config = $this->processConfiguration($configuration, $configs);

        if ($container->hasDefinition(HttpWorker::class)) {
            $definition = $container->getDefinition(HttpWorker::class);
            $definition->replaceArgument(0, $config["http"]["early_router_initialization"]);
            $definition->replaceArgument(1, $config["http"]["lazy_boot"]);
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
        $pathname = $projectDir . "/" . $rrConfigPath;

        $content = @file_get_contents($pathname);
        if ($content === false) {
            return [];
        }

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse($content) ?? [];

        return $parsed;
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
