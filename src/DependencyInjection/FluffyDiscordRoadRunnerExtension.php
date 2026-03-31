<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoChannelListener;
use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoRpcListener;
use FluffyDiscord\RoadRunnerBundle\Cache\KVCacheAdapter;
use FluffyDiscord\RoadRunnerBundle\Exception\CacheAutoRegisterException;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\KeyValue\Cache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Yaml\Yaml;

class FluffyDiscordRoadRunnerExtension extends Extension
{
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
                static function (ChildDefinition $definition, AsCentrifugoChannelListener $attr, \ReflectionClass|\ReflectionMethod $refl): void {
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
                static function (ChildDefinition $definition, AsCentrifugoRpcListener $attr, \ReflectionClass|\ReflectionMethod $refl): void {
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
        /** @var array{http: array{early_router_initialization: bool, lazy_boot: bool}, centrifugo: array{lazy_boot: bool}, kv: array{auto_register: bool, serializer: ?string, keypair_path: ?string}, rr_config_path: ?string} $config */
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

        if (class_exists(Cache::class) && $config["kv"]["auto_register"] === true) {
            $rrConfig = $this->getRoadRunnerConfig($container, $config);

            /** @var array<string, mixed> $kvConfig */
            $kvConfig = $rrConfig["kv"] ?? [];
            foreach (array_keys($kvConfig) as $name) {
                $container
                    ->register("cache.adapter.rr_kv.{$name}", KVCacheAdapter::class)
                    ->setFactory([KVCacheAdapter::class, "create"])
                    ->setArguments([
                        "", // namespace, dummy
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

    /** @param array{rr_config_path: ?string} $config
     *  @return array<string, mixed>
     */
    private function getRoadRunnerConfig(ContainerBuilder $container, array $config): array
    {
        try {
            $rpc = $container->get(RPCInterface::class);
        } catch (InvalidRPCConfigurationException $invalidRPCConfigurationException) {
            throw new CacheAutoRegisterException($invalidRPCConfigurationException->getMessage(), previous: $invalidRPCConfigurationException);
        }

        assert($rpc instanceof RPCInterface);

        try {
            /** @var string $rpcResult */
            $rpcResult = $rpc->call("rpc.Config", null);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(base64_decode($rpcResult), true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (\JsonException $jsonException) {
            throw new CacheAutoRegisterException($jsonException->getMessage(), previous: $jsonException);
        } catch (RelayException $relayException) {
            if ($config["rr_config_path"] !== null) {
                /** @var string $projectDir */
                $projectDir = $container->getParameter("kernel.project_dir");
                $rrConfigPathname = $projectDir . "/" . $config["rr_config_path"];
                if (!file_exists($rrConfigPathname)) {
                    throw new CacheAutoRegisterException(sprintf('Specified RoadRunner config was not found: %s', $rrConfigPathname), previous: $relayException);
                }

                $yamlConfig = @file_get_contents($rrConfigPathname);
                if ($yamlConfig === false) {
                    throw new CacheAutoRegisterException(sprintf('Unable to read RoadRunner config, check permissions: %s', $rrConfigPathname), previous: $relayException);
                }

                /** @var array<string, mixed> */
                return Yaml::parse($yamlConfig);
            }

            throw new CacheAutoRegisterException('Error connecting to RPC service. Is RoadRunner running? Optionally set "rr_config_path" in bundle\'s config.', previous: $relayException);
        }
    }
}
