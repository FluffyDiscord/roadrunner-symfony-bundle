<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Worker\WorkerOptions;

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
                ->arrayNode("jobs")
                    ->info($this->toInfo([
                        'Jobs (queue consumer)',
                        'Will activate only when "spiral/roadrunner-jobs" is installed.',
                        'https://docs.roadrunner.dev/queues-and-jobs/overview-queues',
                    ]))
                    ->children()
                        ->booleanNode("lazy_boot")
                            ->info($this->toInfo([
                                'See http section,',
                                'behaves the same way.',
                            ]))
                            ->defaultFalse()
                        ->end()
                        ->enumNode("serializer")
                            ->info($this->toInfo([
                                'Serialization strategy for the Jobs message bus.',
                                '',
                                'By default (null), "igbinary" is used when the "igbinary" php',
                                'extension is installed, otherwise "native".',
                                '',
                                '"igbinary" uses the igbinary extension.',
                                '"native" uses PHP serialize/unserialize.',
                                '"symfony" uses the Symfony Serializer component (JSON, requires symfony/serializer).',
                            ]))
                            ->values(["igbinary", "native", "symfony"])
                            ->defaultNull()
                        ->end()
                        ->scalarNode("default_queue")
                            ->info($this->toInfo([
                                'Default queue/pipeline name used by JobDispatcher',
                                'when a dispatched message has neither an explicit',
                                'queue argument nor a #[AsJob(queue: ...)] default.',
                                'The pipeline must already exist in your .rr.yaml.',
                            ]))
                            ->cannotBeEmpty()
                            ->defaultValue("default")
                        ->end()
                        ->scalarNode("bus")
                            ->info($this->toInfo([
                                'Service id of the Symfony Messenger bus the Jobs',
                                'consumer dispatches into. Null (default) uses the',
                                'application default bus (MessageBusInterface).',
                                'Only relevant with symfony/messenger installed and',
                                'multiple buses defined.',
                            ]))
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
            ->end()
        ;

        if (class_exists(WorkerOptions::class)) {
            $this->addTemporalNode($builder->getRootNode());
        }

        return $builder;
    }

    private function addTemporalNode(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('temporal')
                    ->info($this->toInfo([
                        'Temporal',
                        'Will activate only when "temporal/sdk" is installed.',
                        'https://docs.roadrunner.dev/docs/plugins/temporal',
                    ]))
                    ->children()
                        ->scalarNode('namespace')
                            ->info($this->toInfo([
                                'Temporal namespace used by the autowired clients.',
                            ]))
                            ->defaultValue('default')
                        ->end()
                        ->booleanNode('tracing')
                            ->info($this->toInfo([
                                'Enable the bundle\'s opt-in tracing listener: logs selected',
                                'interceptor events on the "temporal" Monolog channel, adds Sentry',
                                'breadcrumbs when Sentry is present, and propagates a correlation id',
                                'into started workflows\' headers. Off by default.',
                            ]))
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('api_key')
                            ->info($this->toInfo([
                                'API key to connect to your Temporal instance',
                            ]))
                            ->defaultNull()
                        ->end()
                        ->arrayNode('retryable_errors')
                            ->info($this->toInfo([
                                'Array list of exceptions',
                                'that will let Temporal know that the workflows',
                                'can be retried. It\'s being checked as $error instanceOf YourException',
                                'so keep that in mind. Exceptions not listed will stop workflow execution.',
                                'By default everything extending '.\Error::class.' can be retried.',
                                'If you need something custom, decorate or register your own interceptor.',
                                'More info at '.ExceptionInterceptorInterface::class,
                            ]))
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                \Error::class,
                            ])
                        ->end()
                        ->arrayNode('default_worker_options')
                            ->info($this->toInfo([
                                'Shortcut to set default worker options,',
                                'instead of creating your own class just for that. '.
                                'Available options: '.WorkerOptions::class,
                            ]))
                            ->prototype('variable')->end()
                            ->validate()
                                ->always($this->workerOptionsValidator())
                            ->end()
                        ->end()
                        ->arrayNode('worker_options')
                            ->info($this->toInfo([
                                'Per-task-queue worker options, keyed by task queue name.',
                                'Applies to the workers the bundle auto-registers for queues',
                                'declared via #[TaskQueue]. The "default" queue is covered',
                                'by "default_worker_options" above. Available options: '.WorkerOptions::class,
                            ]))
                            ->useAttributeAsKey('task_queue')
                            ->arrayPrototype()
                                ->prototype('variable')->end()
                                ->validate()
                                    ->always($this->workerOptionsValidator())
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
            ->end()
        ;
    }

    /**
     * @return \Closure(mixed): mixed
     */
    private function workerOptionsValidator(): \Closure
    {
        return static function ($v) {
            if (!is_array($v)) {
                return $v;
            }

            $validOptions = array_keys(get_class_vars(WorkerOptions::class));
            foreach (array_keys($v) as $key) {
                if (!in_array($key, $validOptions, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Unknown worker option "%s". Available options are: %s',
                        (string) $key,
                        implode(', ', $validOptions),
                    ));
                }
            }

            return $v;
        };
    }

    /** @param array<string> $lines */
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
        if(!isset($_SERVER["PHP_SELF"]) || !is_string($_SERVER["PHP_SELF"])) {
            return false;
        }

        if(!str_contains($_SERVER["PHP_SELF"], "console")) {
            return false;
        }

        if(!isset($_SERVER["argv"]) || !is_array($_SERVER["argv"])) {
            return false;
        }

        /** @var array<string> $argv */
        $argv = $_SERVER["argv"];
        return in_array("config:dump-reference", $argv);
    }
}
