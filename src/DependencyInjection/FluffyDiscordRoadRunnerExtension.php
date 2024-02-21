<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Configuration\Configuration;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
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

        if (isset($config["http"]["lazy_boot"]) && !$config["http"]["lazy_boot"] && $container->hasDefinition(CentrifugoWorker::class)) {
            $definition = $container->getDefinition(HttpWorker::class);
            $definition->replaceArgument(0, false);
        }

        if (isset($config["centrifugo"]["lazy_boot"]) && !$config["centrifugo"]["lazy_boot"] && $container->hasDefinition(CentrifugoWorker::class)) {
            $definition = $container->getDefinition(CentrifugoWorker::class);
            $definition->replaceArgument(0, false);
        }
    }
}
