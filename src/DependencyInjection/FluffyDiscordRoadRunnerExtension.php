<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Cache\KVCacheAdapter;
use FluffyDiscord\RoadRunnerBundle\Configuration\Configuration;
use FluffyDiscord\RoadRunnerBundle\Exception\CacheAutoRegisterException;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\RPC\RPCInterface;
use Symfony\Component\Config\FileLocator;
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

        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($container->hasDefinition(HttpWorker::class)) {
            if (isset($config["http"]["early_router_initialization"])) {
                $definition = $container->getDefinition(HttpWorker::class);
                $definition->replaceArgument(0, $config["http"]["early_router_initialization"]);
            }

            if (isset($config["http"]["lazy_boot"])) {
                $definition = $container->getDefinition(HttpWorker::class);
                $definition->replaceArgument(1, $config["http"]["lazy_boot"]);
            }
        }

        if (isset($config["centrifugo"]["lazy_boot"]) && $container->hasDefinition(CentrifugoWorker::class)) {
            $definition = $container->getDefinition(CentrifugoWorker::class);
            $definition->replaceArgument(0, $config["centrifugo"]["lazy_boot"]);
        }

        if (!isset($config["kv"]["auto_register"]) || $config["kv"]["auto_register"]) {
            $rrConfig = $this->getRoadRunnerConfig($container, $config);

            foreach (array_keys($rrConfig["kv"] ?? []) as $name) {
                $container
                    ->register("cache.adapter.rr_kv.{$name}", KVCacheAdapter::class)
                    ->setFactory([KVCacheAdapter::class, "create"])
                    ->setArguments([
                        "", // namespace, dummy
                        $container->getDefinition(RPCInterface::class),
                        $name,
                        $container->getParameter("kernel.project_dir"),
                        $config["kv"]["serializer"] ?? null,
                        $config["kv"]["keypair_path"] ?? null,
                    ])
                ;
            }
        }
    }

    private function getRoadRunnerConfig(ContainerBuilder $container, array $config): array
    {
        try {
            $rpc = $container->get(RPCInterface::class);
        } catch (InvalidRPCConfigurationException $invalidRPCConfigurationException) {
            throw new CacheAutoRegisterException($invalidRPCConfigurationException->getMessage(), previous: $invalidRPCConfigurationException);
        }

        try {
            return json_decode(base64_decode($rpc->call("rpc.Config", null)), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new CacheAutoRegisterException($jsonException->getMessage(), previous: $jsonException);
        } catch (RelayException $relayException) {
            if ($config["rr_config_path"] !== null) {
                $rrConfigPathname = $container->getParameter("kernel.project_dir") . "/" . $config["rr_config_path"];
                if (!file_exists($rrConfigPathname)) {
                    throw new CacheAutoRegisterException(sprintf('Specified RoadRunner config was not found: %s', $rrConfigPathname), previous: $relayException);
                }

                $yamlConfig = @file_get_contents($rrConfigPathname);
                if ($yamlConfig === false) {
                    throw new CacheAutoRegisterException(sprintf('Unable to read RoadRunner config, check permissions: %s', $rrConfigPathname), previous: $relayException);
                }

                return Yaml::parse($yamlConfig);
            }

            throw new CacheAutoRegisterException('Error connecting to RPC service. Is RoadRunner running? Optionally set "rr_config_path" in bundle\'s config.', previous: $relayException);
        }
    }
}
