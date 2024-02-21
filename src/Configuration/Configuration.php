<?php

namespace FluffyDiscord\RoadRunnerBundle\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder("fluffydiscord_roadrunner");

        $builder->getRootNode()
            ->children()
                ->arrayNode("http")->children()
                    ->booleanNode("lazy_boot")->defaultFalse()->end()
                ->end()
                ->arrayNode("centrifugo")->children()
                    ->booleanNode("lazy_boot")->defaultFalse()->end()
                ->end()
            ->end()
        ;

        return $builder;
    }
}
