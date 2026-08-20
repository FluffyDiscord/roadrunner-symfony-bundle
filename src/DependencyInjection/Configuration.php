<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Temporal\Exception\ExceptionInterceptorInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface as GrpcServiceInterface;
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
                        'See docs/specs/worker-warmup.md.',
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

        if (interface_exists(GrpcServiceInterface::class)) {
            $this->addGrpcNode($builder->getRootNode());
        }

        return $builder;
    }

    private function addGrpcNode(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('grpc')
                    ->info($this->toInfo([
                        'gRPC',
                        'Will activate only when "spiral/roadrunner-grpc" is installed.',
                        'https://docs.roadrunner.dev/docs/grpc/grpc',
                    ]))
                    ->children()
                        ->booleanNode('tracing')
                            ->info($this->toInfo([
                                'Enable the bundle\'s opt-in tracing listener: logs every gRPC call',
                                'on the "grpc" Monolog channel (metadata keys only, never values) and',
                                'adds Sentry breadcrumbs when Sentry is present. Off by default.',
                            ]))
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('profiler')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('redacted_metadata_keys')
                                    ->info($this->toInfo([
                                        'Metadata keys whose values the profiler panel shows as "••••••".',
                                        'security.metadata_key is always added.',
                                    ]))
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['authorization', 'proxy-authorization', 'cookie'])
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('security')
                            ->info($this->toInfo([
                                'Authenticate incoming gRPC calls through Symfony Security:',
                                'a bearer token in call metadata is resolved by your',
                                'AccessTokenHandlerInterface (the same contract the access_token',
                                'firewall authenticator uses) and put into the token storage,',
                                'so handlers can use Security::getUser() and #[IsGranted].',
                                'Requires symfony/security-bundle.',
                            ]))
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('token_handler')
                                    ->info($this->toInfo(['Service id implementing AccessTokenHandlerInterface. Required when enabled.']))
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('metadata_key')
                                    ->info($this->toInfo(['Metadata key carrying the token.']))
                                    ->defaultValue('authorization')
                                ->end()
                                ->scalarNode('token_prefix')
                                    ->info($this->toInfo(['Prefix stripped case-insensitively from the metadata value; "" for a raw token.']))
                                    ->defaultValue('Bearer ')
                                ->end()
                                ->booleanNode('required')
                                    ->info($this->toInfo([
                                        'true: a call without the metadata key is answered UNAUTHENTICATED.',
                                        'false: such a call runs anonymously; #[IsGranted] methods still',
                                        'answer UNAUTHENTICATED. An invalid token is always UNAUTHENTICATED.',
                                    ]))
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('firewall_name')
                                    ->info($this->toInfo(['Firewall name stored on the token (a label: shown in the Security profiler panel).']))
                                    ->defaultValue('grpc')
                                ->end()
                                ->scalarNode('user_provider')
                                    ->info($this->toInfo([
                                        'Service id of a UserProviderInterface used when the token handler\'s',
                                        'UserBadge has no user loader. Defaults to the autowired alias,',
                                        'which exists only when exactly one provider is configured.',
                                    ]))
                                    ->defaultNull()
                                ->end()
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $security): bool => $security['enabled'] === true && $security['token_handler'] === null)
                                ->thenInvalid('fluffy_discord_road_runner.grpc.security.token_handler is required when grpc.security.enabled is true')
                            ->end()
                        ->end()
                    ->end()
                    ->addDefaultsIfNotSet()
                ->end()
            ->end()
        ;
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
            foreach (array_keys($v) as $rawKey) {
                $key = (string) $rawKey;
                if (!in_array($key, $validOptions, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Unknown worker option "%s". Available options are: %s',
                        $key,
                        implode(', ', $validOptions),
                    ));
                }

                // Only scalar and \DateInterval options can be carried by the array config; the
                // remaining properties (enums like workflowPanicPolicy, value objects) would be
                // accepted here yet TypeError when the worker assigns them at boot.
                $type = (new \ReflectionProperty(WorkerOptions::class, $key))->getType();
                if (!$type instanceof \ReflectionNamedType || !in_array($type->getName(), ['int', 'float', 'bool', 'string', 'DateInterval'], true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Worker option "%s" cannot be set from configuration; set it via a custom %s.',
                        $key,
                        TemporalWorkerInterface::class,
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
