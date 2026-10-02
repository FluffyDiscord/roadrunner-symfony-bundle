<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Configuration;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Temporal\Worker\WorkerDeploymentOptions;
use Temporal\Worker\WorkerOptions;

/**
 * TC-16 — the `temporal` config node (only defined when temporal/sdk is installed).
 */
class TemporalConfigurationTest extends BaseTestCase
{
    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array<string, mixed>
     */
    private function processConfig(array $configs = [[]]): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }

    /**
     * @param array<string, mixed> $queueOptions
     * @return array<string, mixed>
     */
    private function processQueueOptions(array $queueOptions): array
    {
        $config = $this->processConfig([[
            'temporal' => [
                'worker_options' => ['billing' => $queueOptions],
            ],
        ]]);

        return $config['temporal']['worker_options']['billing'];
    }

    public function testTemporalDefaults(): void
    {
        $config = $this->processConfig();

        self::assertArrayHasKey('temporal', $config);
        self::assertNull($config['temporal']['api_key']);
        self::assertSame([\Error::class], $config['temporal']['retryable_errors']);
        self::assertSame('default', $config['temporal']['namespace']);
        self::assertFalse($config['temporal']['tracing']);
        self::assertSame(['rpc_timeout' => 5.0, 'rpc_max_attempts' => 3], $config['temporal']['client']);
        self::assertTrue($config['temporal']['non_retryable_activity_errors']);
        self::assertSame([], $config['temporal']['worker_options']);
    }

    public function testClientLimitsPassThrough(): void
    {
        $config = $this->processConfig([[
            'temporal' => [
                'client' => ['rpc_timeout' => 2.5, 'rpc_max_attempts' => 0],
            ],
        ]]);

        self::assertSame(['rpc_timeout' => 2.5, 'rpc_max_attempts' => 0], $config['temporal']['client']);
    }

    public function testZeroRpcTimeoutIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([[
            'temporal' => [
                'client' => ['rpc_timeout' => 0],
            ],
        ]]);
    }

    public function testApiKeyAndRetryableErrorsPassThrough(): void
    {
        $config = $this->processConfig([[
            'temporal' => [
                'api_key' => 'secret',
                'retryable_errors' => [\LogicException::class, \RuntimeException::class],
            ],
        ]]);

        self::assertSame('secret', $config['temporal']['api_key']);
        self::assertSame([\LogicException::class, \RuntimeException::class], $config['temporal']['retryable_errors']);
    }

    public function testNamespaceAndTracingPassThrough(): void
    {
        $config = $this->processConfig([[
            'temporal' => [
                'namespace' => 'orders',
                'tracing' => true,
            ],
        ]]);

        self::assertSame('orders', $config['temporal']['namespace']);
        self::assertTrue($config['temporal']['tracing']);
    }

    /**
     * @return array<string, string>
     */
    private static function getOptionNamesByPropertyName(): array
    {
        return [
            'maxConcurrentActivityExecutionSize'      => 'max_concurrent_activity_execution_size',
            'workerActivitiesPerSecond'               => 'worker_activities_per_second',
            'maxConcurrentLocalActivityExecutionSize' => 'max_concurrent_local_activity_execution_size',
            'workerLocalActivitiesPerSecond'          => 'worker_local_activities_per_second',
            'taskQueueActivitiesPerSecond'            => 'task_queue_activities_per_second',
            'maxConcurrentActivityTaskPollers'        => 'max_concurrent_activity_task_pollers',
            'maxConcurrentWorkflowTaskExecutionSize'  => 'max_concurrent_workflow_task_execution_size',
            'maxConcurrentWorkflowTaskPollers'        => 'max_concurrent_workflow_task_pollers',
            'maxConcurrentNexusTaskExecutionSize'     => 'max_concurrent_nexus_task_execution_size',
            'maxConcurrentNexusTaskPollers'           => 'max_concurrent_nexus_task_pollers',
            'enableLoggingInReplay'                   => 'enable_logging_in_replay',
            'stickyScheduleToStartTimeout'            => 'sticky_schedule_to_start_timeout',
            'workflowPanicPolicy'                     => 'workflow_panic_policy',
            'workerStopTimeout'                       => 'worker_stop_timeout',
            'enableSessionWorker'                     => 'enable_session_worker',
            'sessionResourceId'                       => 'session_resource_id',
            'maxConcurrentSessionExecutionSize'       => 'max_concurrent_session_execution_size',
            'disableWorkflowWorker'                   => 'disable_workflow_worker',
            'localActivityWorkerOnly'                 => 'local_activity_worker_only',
            'identity'                                => 'identity',
            'deadlockDetectionTimeout'                => 'deadlock_detection_timeout',
            'maxHeartbeatThrottleInterval'            => 'max_heartbeat_throttle_interval',
            'disableEagerActivities'                  => 'disable_eager_activities',
            'maxConcurrentEagerActivityExecutionSize' => 'max_concurrent_eager_activity_execution_size',
            'disableRegistrationAliasing'             => 'disable_registration_aliasing',
            'buildID'                                 => 'build_id',
            'useBuildIDForVersioning'                 => 'use_build_id_for_versioning',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configurableWorkerOptions(): iterable
    {
        foreach (self::getOptionNamesByPropertyName() as $propertyName => $optionName) {
            yield $propertyName => [$optionName, $propertyName];
        }
    }

    public function testEveryConfigurableWorkerOptionIsListed(): void
    {
        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(WorkerOptions::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        $configurablePropertyNames = array_values(array_diff($propertyNames, ['deploymentOptions']));

        self::assertEqualsCanonicalizing($configurablePropertyNames, array_keys(self::getOptionNamesByPropertyName()));
    }

    #[DataProvider('configurableWorkerOptions')]
    public function testEveryWorkerOptionIsConfigurableInSnakeCase(string $optionName, string $propertyName): void
    {
        $type = (new \ReflectionProperty(WorkerOptions::class, $propertyName))->getType();
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

        $value = match ($typeName) {
            'int'          => 3,
            'float'        => 1.5,
            'bool'         => true,
            'string'       => 'value',
            'DateInterval' => '30 seconds',
            default        => 'FailWorkflow',
        };

        self::assertSame([$propertyName => $value], $this->processQueueOptions([$optionName => $value]));
    }

    public function testAcronymPropertiesKeepTheirSnakeCaseName(): void
    {
        self::assertSame(
            ['buildID' => 'v1', 'useBuildIDForVersioning' => true],
            $this->processQueueOptions(['build_id' => 'v1', 'use_build_id_for_versioning' => true]),
        );
    }

    public function testDurationAcceptsSeconds(): void
    {
        self::assertSame(['workerStopTimeout' => 30], $this->processQueueOptions(['worker_stop_timeout' => 30]));
    }

    public function testDurationAcceptsNumericStringSeconds(): void
    {
        self::assertSame(['workerStopTimeout' => '30'], $this->processQueueOptions(['worker_stop_timeout' => '30']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'unknown unit' => ['30 blorps'];
        yield 'no amount' => ['bogus'];
        yield 'spelled-out amount' => ['thirty seconds'];
    }

    #[DataProvider('invalidDurations')]
    public function testUnparseableDurationIsRejected(string $duration): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('worker_stop_timeout');

        $this->processQueueOptions(['worker_stop_timeout' => $duration]);
    }

    public function testUnknownEnumCaseIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('workflow_panic_policy');

        $this->processQueueOptions(['workflow_panic_policy' => 'Explode']);
    }

    public function testUnknownWorkerOptionIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "maxConcurrentActivityExecutionSize"');

        $this->processQueueOptions(['maxConcurrentActivityExecutionSize' => 1]);
    }

    public function testDeploymentOptionsAreNotConfigurable(): void
    {
        self::assertTrue(class_exists(WorkerDeploymentOptions::class));

        $this->expectException(InvalidConfigurationException::class);

        $this->processQueueOptions(['deployment_options' => []]);
    }

    public function testDashedQueueNamesAreKept(): void
    {
        $config = $this->processConfig([[
            'temporal' => [
                'worker_options' => ['billing-eu' => ['max_concurrent_activity_execution_size' => 2]],
            ],
        ]]);

        self::assertSame(['billing-eu'], array_keys($config['temporal']['worker_options']));
    }
}
