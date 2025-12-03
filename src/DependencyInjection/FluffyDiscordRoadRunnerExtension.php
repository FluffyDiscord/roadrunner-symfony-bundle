<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use App\Compiler\Collector\CollectorInterface;
use FluffyDiscord\RoadRunnerBundle\Cache\KVCacheAdapter;
use FluffyDiscord\RoadRunnerBundle\Exception\CacheAutoRegisterException;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TemporalTaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\KeyValue\Cache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;
use Temporal\Activity\ActivityInterface;
use Temporal\Workflow\WorkflowInterface;

class FluffyDiscordRoadRunnerExtension extends Extension implements CompilerPassInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('monolog')) {
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

        $configuration = $this->getConfiguration([], $container);
        $config = $this->processConfiguration($configuration, $configs);

        if ($container->hasDefinition(HttpWorker::class)) {
            $this->registerHttpWorker($container, $config);
        }

        if (isset($config["centrifugo"]["lazy_boot"]) && $container->hasDefinition(CentrifugoWorker::class)) {
            $definition = $container->getDefinition(CentrifugoWorker::class);
            $definition->replaceArgument(0, $config["centrifugo"]["lazy_boot"]);
        }

        if (class_exists(Cache::class) && (!isset($config["kv"]["auto_register"]) || $config["kv"]["auto_register"] === true)) {
            $this->registerKVCache($config, $container);
        }
    }

    public function process(ContainerBuilder $container): void
    {
        if (class_exists(WorkflowInterface::class)) {
            $this->registerTemporal($container);
        }
    }

    private function getDefinitionClassInterfaces(Definition $definition): array
    {
        $class = $this->getClassFromDefinition($definition);
        if ($class === null) {
            return [];
        }

        $interfaces = class_implements($class);
        if ($interfaces === false) {
            return [];
        }

        return $interfaces;
    }

    private function getClassFromDefinition(Definition $definition): string|null
    {
        if ($definition->getClass() === null) {
            return null;
        }

        try {
            if (!class_exists($definition->getClass())) {
                return null;
            }

            // catching Symfony\Component\ErrorHandler\Error\ClassNotFoundError does not work
        } catch (\Throwable) {
            return null;
        }

        return $definition->getClass();
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

    private function registerKVCache(array $config, ContainerBuilder $container): void
    {
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

    private function registerHttpWorker(ContainerBuilder $container, array $config): void
    {
        if (isset($config["http"]["early_router_initialization"])) {
            $definition = $container->getDefinition(HttpWorker::class);
            $definition->replaceArgument(0, $config["http"]["early_router_initialization"]);
        }

        if (isset($config["http"]["lazy_boot"])) {
            $definition = $container->getDefinition(HttpWorker::class);
            $definition->replaceArgument(1, $config["http"]["lazy_boot"]);
        }
    }

    /**
     * @param ContainerBuilder $container
     * @return void
     */
    private function registerTemporal(ContainerBuilder $container): void
    {
        $workerInitializer = $container->getDefinition(TemporalWorkerInitializer::class);

        foreach ($container->getDefinitions() as $definition) {
            $interfaces = $this->getDefinitionClassInterfaces($definition);
            if (in_array(TemporalWorkerInterface::class, $interfaces)) {
                $workerInitializer->addMethodCall('addWorker', [new Reference($definition->getClass())]);
            }
        }

        $container->registerAttributeForAutoconfiguration(TemporalTaskQueue::class, static function (ChildDefinition $definition, TemporalTaskQueue $attribute, \ReflectionClass $reflector) use ($workerInitializer): void {
            $isActivity = false;
            $isWorkflow = false;

            $classReflection = $reflector;
            while (true) {
                foreach ($classReflection->getAttributes() as $attribute) {
                    if ($attribute->newInstance() instanceof ActivityInterface) {
                        $isActivity = true;
                        break;
                    }

                    if ($attribute->newInstance() instanceof WorkflowInterface) {
                        $isWorkflow = true;
                        break;
                    }
                }

                $classReflection = $classReflection->getParentClass();
                if ($classReflection === false) {
                    break;
                }
            }

            if (!$isActivity && !$isWorkflow) {
                foreach ($reflector->getInterfaces() as $interface) {
                    foreach ($interface->getAttributes() as $attribute) {
                        if ($attribute->newInstance() instanceof ActivityInterface) {
                            $isActivity = true;
                            break 2;
                        }

                        if ($attribute->newInstance() instanceof WorkflowInterface) {
                            $isWorkflow = true;
                            break 2;
                        }
                    }
                }
            }

            if ($isActivity) {
                // make all activities as factories
                $definition->setShared(false);

                // pass just a string
                $workerInitializer->addMethodCall('addActivity', [$workerInitializer->getClass()]);

                $definition->addTag('fluffydiscord.roadrunner.temporal.activity');
            }

            if ($isWorkflow) {
                // pass just a string
                $workerInitializer->addMethodCall('addWorkflow', [$workerInitializer->getClass()]);

                $definition->addTag('fluffydiscord.roadrunner.temporal.workflow');
            }
        });
    }
}
