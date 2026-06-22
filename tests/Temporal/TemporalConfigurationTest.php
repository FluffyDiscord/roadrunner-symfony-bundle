<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Configuration;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

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

    public function testTemporalDefaults(): void
    {
        $config = $this->processConfig();

        self::assertArrayHasKey('temporal', $config);
        self::assertNull($config['temporal']['api_key']);
        self::assertSame([\Error::class], $config['temporal']['retryable_errors']);
        self::assertSame([], $config['temporal']['default_worker_options']);
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

    public function testClientAndTracingDefaults(): void
    {
        $config = $this->processConfig();

        self::assertSame('default', $config['temporal']['namespace']);
        self::assertFalse($config['temporal']['tracing']);
        self::assertSame([], $config['temporal']['worker_options']);
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

    public function testPerQueueWorkerOptionsAreValidated(): void
    {
        $config = $this->processConfig([[
            'temporal' => [
                'worker_options' => ['billing' => ['maxConcurrentActivityExecutionSize' => 3]],
            ],
        ]]);

        self::assertSame(['maxConcurrentActivityExecutionSize' => 3], $config['temporal']['worker_options']['billing']);
    }

    public function testUnknownPerQueueWorkerOptionIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown worker option "nope"');

        $this->processConfig([[
            'temporal' => [
                'worker_options' => ['billing' => ['nope' => 1]],
            ],
        ]]);
    }

    public function testValidWorkerOptionPassesValidation(): void
    {
        $config = $this->processConfig([[
            'temporal' => [
                'default_worker_options' => ['maxConcurrentActivityExecutionSize' => 5],
            ],
        ]]);

        self::assertSame(['maxConcurrentActivityExecutionSize' => 5], $config['temporal']['default_worker_options']);
    }

    public function testUnknownWorkerOptionIsRejected(): void
    {
        // The validator throws \InvalidArgumentException; the Symfony config layer
        // wraps it in InvalidConfigurationException but preserves the message.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown worker option "thisDoesNotExist"');

        $this->processConfig([[
            'temporal' => [
                'default_worker_options' => ['thisDoesNotExist' => 1],
            ],
        ]]);
    }

    public function testNonScalarWorkerOptionIsRejected(): void
    {
        // workflowPanicPolicy is a WorkflowPanicPolicy enum — the array config can't carry it, so the
        // validator rejects it with guidance instead of letting it TypeError when the worker boots.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Worker option "workflowPanicPolicy" cannot be set from configuration');

        $this->processConfig([[
            'temporal' => [
                'default_worker_options' => ['workflowPanicPolicy' => 0],
            ],
        ]]);
    }
}
