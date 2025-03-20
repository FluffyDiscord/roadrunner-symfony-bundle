<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder("fluffy_discord_road_runner");

        $builder->getRootNode()
            ->info($this->toInfo([
                'https://github.com/FluffyDiscord/roadrunner-symfony-bundle',
            ]))
            ->children()
                ->scalarNode("rr_config_path")
                    ->info($this->toInfo([
                        'Specify relative path from "kernel.project_dir"',
                        'to your RoadRunner config file if you want to',
                        'run cache:warmup without having your RoadRunner',
                        'running in background, e.g. when building Docker images.',
                    ]))
                    ->defaultValue(".rr.yaml")
                ->end()
                ->arrayNode("http")
                    ->info($this->toInfo([
                        'Http worker',
                        'https://docs.roadrunner.dev/http/http',
                    ]))
                    ->children()
                        ->booleanNode("lazy_boot")
                            ->info($this->toInfo([
                                'This decides when to boot the Symfony kernel.',
                                '',
                                'false (default) - before first request (worker takes some time',
                                'to be ready, but app has consistent response times)',
                                'true - once first request arrives (worker is ready immediately,',
                                'but inconsistent response times due to kernel boot time spikes)',
                                '',
                                'If you use large amount of workers, you might want to set this',
                                'to true or else the RR boot up might take a lot of time',
                                'or just boot up using only a few "emergency" workers',
                                'and then use dynamic worker scaling as described here',
                                'https://docs.roadrunner.dev/php-worker/scaling',
                            ]))
                            ->defaultFalse()
                        ->end()
                        ->booleanNode("early_router_initialization")
                            ->info($this->toInfo([
                                'This decides if Symfony routing should be preloaded',
                                'when worker starts and boots Symfony kernel.',
                                '',
                                'This option halves the initial request response time.',
                                '(based on a project with over 400 routes',
                                'and quite a lot of services, YMMW)',
                                '',
                                'true - sends one dummy (empty) HTTP request',
                                'for kernel to initialize routing and services around it',
                                '',
                                'false - only when first request arrives',
                                'routing and it\'s services are loaded',
                                '',
                                'You might want to create a dummy "/"',
                                'route for the route to "land",',
                                'or listen to onKernelRequest events',
                                'and look in the request for the attribute',
                                HttpWorker::class.'::DUMMY_REQUEST_ATTRIBUTE',
                            ]))
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
                ->arrayNode("kv")
                    ->info($this->toInfo([
                        'Key-Value storage',
                        'Will activate only when "spiral/roadrunner-kv" is installed.',
                        'https://docs.roadrunner.dev/key-value/overview-kv',
                    ]))
                    ->children()
                        ->booleanNode("auto_register")
                            ->info($this->toInfo([
                                'If true, bundle will automatically register',
                                'all "kv" adapters in your .rr.yaml.',
                                'Registered services have alias "cache.adapter.rr_kv.NAME"',
                            ]))
                            ->defaultTrue()
                        ->end()
                        ->scalarNode("serializer")
                            ->info($this->toInfo([
                                'Which data serializer should be used.',
                                '',
                                'By default, "IgbinarySerializer" will be used',
                                'if "igbinary" php extension',
                                'is installed, otherwise "DefaultSerializer".',
                                '',
                                'You are free to create your own serializer.',
                                'It needs to implement',
                                'Spiral\RoadRunner\KeyValue\Serializer\SerializerInterface',
                            ]))
                            ->defaultNull()
                        ->end()
                        ->scalarNode("keypair_path")
                            ->info($this->toInfo([
                                'Specify relative path from "kernel.project_dir"',
                                'to a keypair file for end-to-end encryption.',
                                '"sodium" php extension is required.',
                                'https://docs.roadrunner.dev/key-value/overview-kv#end-to-end-value-encryption',
                            ]))
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
                ->arrayNode("centrifugo")
                    ->info($this->toInfo([
                        'Centrifugo (websockets)',
                        'Will activate only when "roadrunner-php/centrifugo" is installed.',
                        'https://docs.roadrunner.dev/plugins/centrifuge',
                    ]))
                    ->children()
                        ->booleanNode("lazy_boot")
                            ->info($this->toInfo([
                                'See http section,',
                                'behaves the same way.',
                            ]))
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
            ->end()
        ;

        return $builder;
    }

    private function toInfo(array $lines): string
    {
        if(!$this->isDumpingDefaultConfiguration()) {
            return implode("\n", $lines);
        }

        $longest = 0;
        $boxLines = [];
        foreach ($lines as $line) {
            $longest = max($longest, strlen($line));
            $boxLines[] = sprintf("│ %s", $line);
        }

        $divider = str_repeat("─", $longest + 2);

        $boxLines = implode("\n", $boxLines);

        return sprintf(<<<TEXT
┌{$divider}
$boxLines
├{$divider}
│
TEXT);
    }

    private function isDumpingDefaultConfiguration(): bool
    {
        if(!isset($_SERVER["PHP_SELF"])) {
            return false;
        }

        // assuming people won't wrap or rename this
        if(!str_contains($_SERVER["PHP_SELF"], "console")) {
            return false;
        }

        if(!isset($_SERVER["argv"]) || !is_array($_SERVER["argv"])) {
            return false;
        }

        return in_array("config:dump-reference", $_SERVER["argv"]);
    }
}
