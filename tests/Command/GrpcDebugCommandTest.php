<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Command;

use FluffyDiscord\RoadRunnerBundle\Command\GrpcDebugCommand;
use FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcSecurityFacts;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\GuardedEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\InvalidSignatureInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\InvalidSignatureService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** TC-19 (command half) */
class GrpcDebugCommandTest extends BaseTestCase
{
    private function makeTester(GrpcServiceRegistry $registry, bool $securityEnabled = false): CommandTester
    {
        $configReader = new RoadRunnerYamlConfigReader(__DIR__ . '/../Grpc/Fixtures/config', 'grpc.rr.yaml');
        $introspector = new GrpcIntrospector($registry, $configReader, new GrpcSecurityFacts($securityEnabled, $securityEnabled ? 'app.token_handler' : null, $securityEnabled ? 'authorization' : null, $securityEnabled ? true : null));

        return new CommandTester(new GrpcDebugCommand($introspector));
    }

    public function testListsServicesMethodsAndServerFacts(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));
        $registry->addService(EchoInterface::class, 'app.echo', GuardedEchoService::class);

        $tester = $this->makeTester($registry);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('bundle.test.Echo', $display);
        self::assertStringContainsString('Ping', $display);
        self::assertStringContainsString('PingRequest', $display);
        self::assertStringContainsString('PingResponse', $display);
        self::assertStringContainsString('tcp://127.0.0.1:9001', $display);
        self::assertStringContainsString('unenforced', $display);
    }

    public function testSecurityEnabledDropsTheUnenforcedMarker(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));
        $registry->addService(EchoInterface::class, 'app.echo', GuardedEchoService::class);

        $tester = $this->makeTester($registry, securityEnabled: true);
        $tester->execute([]);

        self::assertStringNotContainsString('unenforced', $tester->getDisplay());
    }

    public function testEmptyRegistryWarnsAndSucceeds(): void
    {
        $tester = $this->makeTester(new GrpcServiceRegistry(new ServiceLocator([])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No gRPC services registered', $tester->getDisplay());
    }

    public function testInvalidMethodSignatureFailsTheCommand(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));
        $registry->addService(InvalidSignatureInterface::class, 'app.invalid', InvalidSignatureService::class);

        $tester = $this->makeTester($registry);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Invalid gRPC method signatures', $tester->getDisplay());
    }
}
