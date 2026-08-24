<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Debug;

use FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcSecurityFacts;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\GuardedEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\InvalidSignatureInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\InvalidSignatureService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** TC-19 (introspection half) */
class GrpcIntrospectorTest extends BaseTestCase
{
    private function makeIntrospector(GrpcServiceRegistry $registry, bool $securityEnabled = false): GrpcIntrospector
    {
        $configReader = new RoadRunnerYamlConfigReader(__DIR__ . '/../Fixtures/config', 'grpc.rr.yaml');

        return new GrpcIntrospector($registry, $configReader, new GrpcSecurityFacts($securityEnabled, $securityEnabled ? 'app.token_handler' : null, $securityEnabled ? 'authorization' : null, $securityEnabled ? true : null));
    }

    public function testDescribesEveryMethodWithTypesAndAccessAttributes(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));
        $registry->addService(EchoInterface::class, 'app.echo', GuardedEchoService::class);

        $rows = $this->makeIntrospector($registry)->describe();

        self::assertCount(4, $rows);
        $rowsByMethod = array_column(array_map(static fn ($row) => [
            'method' => $row->methodName,
            'input'  => $row->inputType,
            'output' => $row->outputType,
            'access' => $row->accessAttributes,
            'valid'  => $row->isValid(),
        ], $rows), null, 'method');

        self::assertSame(PingRequest::class, $rowsByMethod['Ping']['input']);
        self::assertSame(PingResponse::class, $rowsByMethod['Ping']['output']);
        self::assertSame([], $rowsByMethod['Ping']['access']);
        self::assertSame(['ROLE_USER'], $rowsByMethod['WhoAmI']['access']);
        self::assertTrue($rowsByMethod['WhoAmI']['valid']);
    }

    public function testInvalidSignatureIsReportedWithTheSpiralMessage(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));
        $registry->addService(InvalidSignatureInterface::class, 'app.invalid', InvalidSignatureService::class);

        $rows = $this->makeIntrospector($registry)->describe();

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isValid());
        self::assertNotNull($rows[0]->invalidReason);
    }

    public function testServerFactsComeFromTheYamlAtCallTime(): void
    {
        $facts = $this->makeIntrospector(new GrpcServiceRegistry(new ServiceLocator([])))->getServerFacts();

        self::assertTrue($facts->isConfigured);
        self::assertSame('tcp://127.0.0.1:9001', $facts->listen);
        self::assertFalse($facts->tlsEnabled);
        self::assertSame('no_client_certs', $facts->clientAuthType);
        self::assertSame(['echo.proto'], $facts->protoFiles);
    }
}
