<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** TC-03 */
class GrpcServiceRegistryTest extends BaseTestCase
{
    public function testDescriptorsCarryTheNameConstant(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));
        $registry->addService(EchoInterface::class, 'app.echo', EchoService::class);

        $descriptors = $registry->getDescriptors();

        self::assertCount(1, $descriptors);
        self::assertSame('bundle.test.Echo', $descriptors[0]->serviceName);
        self::assertSame(EchoInterface::class, $descriptors[0]->interface);
    }

    public function testGetServiceResolvesThroughTheLocator(): void
    {
        $echoService = new EchoService();
        $registry = new GrpcServiceRegistry(new ServiceLocator(['app.echo' => static fn (): EchoService => $echoService]));
        $registry->addService(EchoInterface::class, 'app.echo', EchoService::class);

        self::assertSame($echoService, $registry->getService($registry->getDescriptors()[0]));
    }

    public function testInterfaceWithoutStringNameIsRejected(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([]));

        $this->expectException(GrpcServiceConfigurationException::class);

        $registry->addService(\Spiral\RoadRunner\GRPC\ServiceInterface::class, 'app.bare', EchoService::class);
    }
}
