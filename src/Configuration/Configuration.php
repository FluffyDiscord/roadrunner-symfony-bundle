<?php

namespace FluffyDiscord\RoadRunnerBundle\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder("fluffy_discord_road_runner");

        $builder->getRootNode()
            ->children()
                ->arrayNode("http")
                    ->children()
                        ->booleanNode("lazy_boot")->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode("centrifugo")
                    ->children()
                        ->booleanNode("lazy_boot")->defaultFalse()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $builder;
    }
}
