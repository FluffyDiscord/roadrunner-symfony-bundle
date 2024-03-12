<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Configuration\Configuration;
use FluffyDiscord\RoadRunnerBundle\Exception\CacheAutoRegisterException;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCFactory;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Spiral\RoadRunner\Environment;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class FluffyDiscordRoadRunnerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . "/../../config"));
        $loader->load("services.php");

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (isset($config["http"]["lazy_boot"]) && $container->hasDefinition(HttpWorker::class)) {
            $definition = $container->getDefinition(HttpWorker::class);
            $definition->replaceArgument(0, $config["http"]["lazy_boot"]);
        }

        if (isset($config["centrifugo"]["lazy_boot"]) && $container->hasDefinition(CentrifugoWorker::class)) {
            $definition = $container->getDefinition(CentrifugoWorker::class);
            $definition->replaceArgument(0, $config["centrifugo"]["lazy_boot"]);
        }

        if (isset($config["kv"]["auto_register"]) && $config["kv"]["auto_register"]) {
            try {
                // TODO: check if it's possible to pull up factory definition for RPCInterface::class from container and use that instead of hardcoded stuff
                $rpc = RPCFactory::fromEnvironment(Environment::fromGlobals());
            } catch (InvalidRPCConfigurationException $invalidRPCConfigurationException) {
                throw new CacheAutoRegisterException($invalidRPCConfigurationException->getMessage(), previous: $invalidRPCConfigurationException);
            }

            $rrConfig = $rpc->call("rpc.Config", null);
            // TODO: check config structure and register services
        }
    }
}
