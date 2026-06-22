<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCConnectionFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorkerFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalCredentialsFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\EnvironmentInterface;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

#[AllowMockObjectsWithoutExpectations]
class FactoriesTest extends BaseTestCase
{
    // TC-08 — credentials factory
    public function testCredentialsFactoryNullApiKey(): void
    {
        $credentials = TemporalCredentialsFactory::create(null);

        self::assertInstanceOf(ServiceCredentials::class, $credentials);
        self::assertSame('', $credentials->apiKey);
    }

    public function testCredentialsFactoryEmptyApiKey(): void
    {
        self::assertSame('', TemporalCredentialsFactory::create('')->apiKey);
    }

    public function testCredentialsFactoryWithApiKey(): void
    {
        self::assertSame('my-key', TemporalCredentialsFactory::create('my-key')->apiKey);
    }

    // TC-09 — RPC connection factory
    public function testRpcConnectionFactoryThrowsWhenRrRpcMissing(): void
    {
        $originalEnv = $_ENV['RR_RPC'] ?? null;
        $originalServer = $_SERVER['RR_RPC'] ?? null;
        unset($_ENV['RR_RPC'], $_SERVER['RR_RPC']);

        try {
            $this->expectException(InvalidRPCConfigurationException::class);
            RPCConnectionFactory::fromEnvironment($this->createMock(EnvironmentInterface::class));
        } finally {
            if ($originalEnv !== null) {
                $_ENV['RR_RPC'] = $originalEnv;
            }
            if ($originalServer !== null) {
                $_SERVER['RR_RPC'] = $originalServer;
            }
        }
    }

    public function testRpcConnectionFactoryReturnsConnectionWhenRrRpcSet(): void
    {
        $original = $_ENV['RR_RPC'] ?? null;
        $_ENV['RR_RPC'] = 'tcp://127.0.0.1:6001';

        $environment = $this->createMock(EnvironmentInterface::class);
        $environment->method('getRPCAddress')->willReturn('tcp://127.0.0.1:6001');

        try {
            $connection = RPCConnectionFactory::fromEnvironment($environment);
            self::assertInstanceOf(RPCConnectionInterface::class, $connection);
        } finally {
            if ($original === null) {
                unset($_ENV['RR_RPC']);
            } else {
                $_ENV['RR_RPC'] = $original;
            }
        }
    }

    // TC-06 — worker factory
    public function testWorkerFactoryCreatesWorkerFactory(): void
    {
        $factory = new DefaultTemporalWorkerFactory(
            $this->createMock(RPCConnectionInterface::class),
            $this->createMock(DataConverterInterface::class),
            ServiceCredentials::create(),
        );

        self::assertInstanceOf(WorkerFactory::class, $factory->create());
    }

    // TC-07 — DefaultTemporalWorker describes a queue and builds its WorkerOptions
    public function testDefaultTemporalWorkerExposesQueueAndOptions(): void
    {
        $defaultWorker = new DefaultTemporalWorker('billing', ['maxConcurrentActivityExecutionSize' => 7]);

        self::assertSame('billing', $defaultWorker->getTaskQueue());

        $options = $defaultWorker->getWorkerOptions();
        self::assertInstanceOf(WorkerOptions::class, $options);
        self::assertSame(7, $options->maxConcurrentActivityExecutionSize);
    }

    // Every \DateInterval-typed worker option must be parsed into a \DateInterval (config carries an
    // int of seconds) — a raw write would TypeError at worker boot. Reflect them all so a newly-added
    // SDK duration option can't silently regress past this guard.
    public function testAllDurationWorkerOptionsAreParsedFromSeconds(): void
    {
        $durations = [];
        foreach ((new \ReflectionClass(WorkerOptions::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'DateInterval') {
                $durations[] = $property->getName();
            }
        }

        self::assertNotEmpty($durations, 'expected WorkerOptions to expose at least one \DateInterval option');

        foreach ($durations as $name) {
            $value = (new DefaultTemporalWorker('default', [$name => 30]))->getWorkerOptions()->{$name};

            self::assertInstanceOf(\DateInterval::class, $value, "{$name} was not parsed into a DateInterval");
            self::assertSame(
                30,
                (new \DateTimeImmutable('@0'))->add($value)->getTimestamp(),
                "{$name} did not round-trip 30 seconds",
            );
        }
    }

    public function testDefaultTemporalWorkerDefaultsToTheDefaultQueue(): void
    {
        self::assertSame(
            WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
            (new DefaultTemporalWorker())->getTaskQueue(),
        );
    }
}
