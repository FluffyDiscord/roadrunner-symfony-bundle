<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Factory\RPCConnectionFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalCredentialsFactory;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\EnvironmentInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\BatchIsolatingHostConnection;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
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

    public function testAllDurationWorkerOptionsAreParsedFromSeconds(): void
    {
        $durations = [];
        foreach ((new \ReflectionClass(WorkerOptions::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'DateInterval') {
                $durations[$property->getName()] = 30;
            }
        }

        self::assertNotEmpty($durations, 'expected WorkerOptions to expose at least one \DateInterval option');

        $initializer = new TemporalWorkerInitializer(
            $this->createStub(KernelInterface::class),
            $this->createStub(BatchIsolatingHostConnection::class),
            new ExceptionInterceptor([\Error::class]),
            new SimplePipelineProvider([]),
            [WorkerFactoryInterface::DEFAULT_TASK_QUEUE => $durations],
        );
        $workerFactory = WorkerFactory::create(DataConverter::createDefault(), $this->createStub(RPCConnectionInterface::class));
        $options = $initializer->initialize($workerFactory)[WorkerFactoryInterface::DEFAULT_TASK_QUEUE]->getOptions();

        foreach (array_keys($durations) as $name) {
            $value = $options->{$name};

            self::assertInstanceOf(\DateInterval::class, $value, "{$name} was not parsed into a DateInterval");
            self::assertSame(
                30,
                (new \DateTimeImmutable('@0'))->add($value)->getTimestamp(),
                "{$name} did not round-trip 30 seconds",
            );
        }
    }
}
