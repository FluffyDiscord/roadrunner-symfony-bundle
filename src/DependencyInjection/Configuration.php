<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\BooleanNodeDefinition;
use Symfony\Component\Config\Definition\Builder\EnumNodeDefinition;
use Symfony\Component\Config\Definition\Builder\FloatNodeDefinition;
use Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\StringNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Internal\Support\DateInterval;
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
                        ->enumNode("request_factory")
                            ->info($this->toInfo([
                                'How RoadRunner requests are converted to Symfony requests.',
                                '',
                                'native (fastest) - build the Symfony Request directly from the',
                                'RoadRunner request, skipping the intermediate PSR-7 object.',
                                'psr7 - the legacy chain: build a PSR-7 request, then convert it',
                                'via symfony/psr-http-message-bridge. Required when you decorate',
                                'the conversion with a custom HttpFoundationFactoryInterface.',
                                'auto (default) - psr7 when a custom HttpFoundationFactoryInterface',
                                'service is registered, native otherwise.',
                                '',
                                'Behavior differences between the paths are documented in',
                                'UPGRADE.md.',
                            ]))
                            ->values(["auto", "native", "psr7"])
                            ->defaultValue("auto")
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
                ->arrayNode("warmup")
                    ->info($this->toInfo([
                        'Worker warmup system: pre-initializes framework infrastructure',
                        '(router, Doctrine metadata + persisters, event listeners, form',
                        'types, Twig runtimes) and replays a learned manifest of',
                        'classes/files real traffic loaded, all while the worker boots,',
                        'before RoadRunner marks it ready. First request then performs',
                        'at steady-state latency. Runs for every worker type on every',
                        'worker boot regardless of "lazy_boot".',
                    ]))
                    ->children()
                        ->booleanNode("enabled")
                            ->info('Master switch for the runner, all built-in warmers and the recorder.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode("learn")
                            ->info($this->toInfo([
                                'Learned-manifest layer: record which classes and cache',
                                'files real responses load, replay them at every',
                                'subsequent worker boot. The manifest only covers routes',
                                'actually visited while learning.',
                            ]))
                            ->defaultTrue()
                        ->end()
                        ->integerNode("learn_requests")
                            ->info('Stop recording after this many responses per worker process.')
                            ->min(1)
                            ->defaultValue(30)
                        ->end()
                        ->scalarNode("manifest_path")
                            ->validate()
                                ->ifTrue(static fn($value) => $value !== null && !is_string($value))
                                ->thenInvalid('warmup.manifest_path must be a string or null.')
                            ->end()
                            ->info($this->toInfo([
                                'Where the learned manifest (JSON) is stored.',
                                'null = <kernel.cache_dir>/roadrunner/warmup.manifest.json',
                                'Point it outside the cache dir to persist learning across',
                                'deploys; the manifest self-invalidates when the container',
                                'build id changes.',
                            ]))
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
                ->arrayNode("doctrine")
                    ->info($this->toInfo([
                        'Doctrine integration.',
                        'Will activate only when "doctrine/dbal" is installed.',
                    ]))
                    ->children()
                        ->booleanNode("preconnect")
                            ->info($this->toInfo([
                                'Open PostgreSQL Doctrine connections at worker boot',
                                '(after the kernel boots, before the first request) so',
                                'the first request skips the PostgreSQL connection',
                                'handshake. Only PostgreSQL connections are touched;',
                                'other drivers are ignored. Requires doctrine/dbal;',
                                'inert without it. Runs on every worker boot regardless',
                                'of "lazy_boot". Set false to opt out (no listener is',
                                'registered).',
                            ]))
                            ->defaultTrue()
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
                        ->append($this->getWorkerOptionsNode())
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
            ->end()
        ;
    }

    private function getWorkerOptionsNode(): ArrayNodeDefinition
    {
        $workerOptionsNode = new ArrayNodeDefinition('worker_options');
        $workerOptionsNode
            ->info($this->toInfo([
                'Temporal SDK worker options per task queue, keyed by queue name',
                '(the name from #[TaskQueue], "default" for the default queue).',
                'Durations take seconds or a duration string ("30 seconds").',
                'Reference: '.WorkerOptions::class,
            ]))
            ->normalizeKeys(false)
            ->useAttributeAsKey('task_queue')
        ;

        $queueNode = $workerOptionsNode->arrayPrototype();
        $propertyNamesByOptionName = [];

        foreach ((new \ReflectionClass(WorkerOptions::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            $hasNamedType = $type instanceof \ReflectionNamedType;
            if (!$hasNamedType) {
                continue;
            }

            $optionName = $this->getSnakeCaseName($property->getName());
            $optionNode = $this->getWorkerOptionNode($optionName, $type->getName());
            if ($optionNode === null) {
                continue;
            }

            $queueNode->append($optionNode);
            $propertyNamesByOptionName[$optionName] = $property->getName();
        }

        $queueNode
            ->validate()
                ->always(function (array $options) use ($propertyNamesByOptionName): array {
                    $optionsByPropertyName = [];
                    foreach ($options as $optionName => $value) {
                        $optionsByPropertyName[$propertyNamesByOptionName[$optionName]] = $value;
                    }

                    return $optionsByPropertyName;
                })
            ->end()
        ;

        return $workerOptionsNode;
    }

    private function getWorkerOptionNode(string $optionName, string $typeName): ?NodeDefinition
    {
        $isEnum = is_a($typeName, \UnitEnum::class, true);
        if ($isEnum) {
            $caseNames = array_map(fn (\UnitEnum $case): string => $case->name, $typeName::cases());

            return (new EnumNodeDefinition($optionName))->values($caseNames);
        }

        return match ($typeName) {
            'int'          => new IntegerNodeDefinition($optionName),
            'float'        => new FloatNodeDefinition($optionName),
            'bool'         => new BooleanNodeDefinition($optionName),
            'string'       => new StringNodeDefinition($optionName),
            'DateInterval' => $this->getDurationNode($optionName),
            default        => null,
        };
    }

    private function getDurationNode(string $optionName): ScalarNodeDefinition
    {
        $durationNode = new ScalarNodeDefinition($optionName);
        $durationNode
            ->validate()
                ->ifTrue(fn (mixed $duration): bool => !$this->isDuration($duration))
                ->thenInvalid('Expected seconds or a duration string such as "30 seconds", got %s.')
            ->end()
        ;

        return $durationNode;
    }

    private function isDuration(mixed $duration): bool
    {
        $isSeconds = is_int($duration);
        if ($isSeconds) {
            return true;
        }

        $isString = is_string($duration);
        if (!$isString) {
            return false;
        }

        $isUnresolvedEnvValue = $duration === '';
        $isNumericSeconds = is_numeric($duration);
        if ($isUnresolvedEnvValue || $isNumericSeconds) {
            return true;
        }

        $hasAmount = preg_match('/\d/', $duration) === 1;
        if (!$hasAmount) {
            return false;
        }

        try {
            DateInterval::parse($duration, DateInterval::FORMAT_SECONDS);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function getSnakeCaseName(string $camelCaseName): string
    {
        $snakeCaseName = preg_replace('/(?<=[a-z0-9])([A-Z])|(?<=[A-Z])([A-Z][a-z])/', '_$1$2', $camelCaseName);
        if ($snakeCaseName === null) {
            throw new \LogicException(sprintf('Unable to derive the configuration name of worker option "%s".', $camelCaseName));
        }

        return strtolower($snakeCaseName);
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
