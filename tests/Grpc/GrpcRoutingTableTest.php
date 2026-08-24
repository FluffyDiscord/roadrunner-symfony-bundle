<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcRoutingTable;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\ExtendedEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\GuardedEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\InvalidSignatureInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\InvalidSignatureService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** TC-04 + the #[IsGranted] precompute of §4.2 */
class GrpcRoutingTableTest extends BaseTestCase
{
    private function buildRegistry(string $serviceId, object $service, string $interface): GrpcServiceRegistry
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator([$serviceId => static fn (): object => $service]));
        $registry->addService($interface, $serviceId, $service::class);

        return $registry;
    }

    public function testRouteCarriesAllInterfaceMethodsWithTypes(): void
    {
        $table = GrpcRoutingTable::fromRegistry($this->buildRegistry('app.echo', new EchoService(), EchoInterface::class));

        $route = $table->getRoute('bundle.test.Echo');

        self::assertNotNull($route);
        self::assertSame(['Ping', 'Fail', 'Crash', 'WhoAmI'], array_keys($route->methods));
        self::assertSame(PingRequest::class, $route->methods['Ping']->method->inputType);
        self::assertSame(PingResponse::class, $route->methods['Ping']->method->outputType);
    }

    public function testIsGrantedAttributesArePrecomputedFromTheHandlerMethod(): void
    {
        $table = GrpcRoutingTable::fromRegistry($this->buildRegistry('app.echo', new GuardedEchoService(), EchoInterface::class));

        $route = $table->getRoute('bundle.test.Echo');

        self::assertNotNull($route);
        self::assertFalse($route->methods['Ping']->hasAccessAttributes());
        self::assertTrue($route->methods['WhoAmI']->hasAccessAttributes());
        self::assertSame('ROLE_USER', $route->methods['WhoAmI']->accessAttributes[0]->attribute);
        self::assertTrue($table->hasAccessAttributes());
    }

    public function testHandlerImplementingViaAnExtendedInterfaceStillRoutes(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator(['app.echo' => static fn (): ExtendedEchoService => new ExtendedEchoService()]));
        $registry->addService(EchoInterface::class, 'app.echo', ExtendedEchoService::class);

        $table = GrpcRoutingTable::fromRegistry($registry);

        self::assertNotNull($table->getRoute('bundle.test.Echo'));
    }

    public function testHandlerNotImplementingTheInterfaceIsRejected(): void
    {
        $registry = new GrpcServiceRegistry(new ServiceLocator(['app.wrong' => static fn (): InvalidSignatureService => new InvalidSignatureService()]));
        $registry->addService(EchoInterface::class, 'app.wrong', InvalidSignatureService::class);

        $this->expectException(GrpcServiceConfigurationException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        GrpcRoutingTable::fromRegistry($registry);
    }

    public function testInvalidInterfaceSignatureFailsWithTheSpiralMessage(): void
    {
        $registry = $this->buildRegistry('app.invalid', new InvalidSignatureService(), InvalidSignatureInterface::class);

        $this->expectException(GrpcServiceConfigurationException::class);
        $this->expectExceptionMessageMatches('/Broken.*not a valid gRPC method/s');

        GrpcRoutingTable::fromRegistry($registry);
    }

    public function testExpressionAccessAttributeIsRejectedAtBuildTime(): void
    {
        $handler = new class extends GuardedEchoService {
            #[\Symfony\Component\Security\Http\Attribute\IsGranted(new \Symfony\Component\ExpressionLanguage\Expression('is_granted("ROLE_USER")'))]
            public function WhoAmI(\Spiral\RoadRunner\GRPC\ContextInterface $ctx, \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest $in): \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse
            {
                return new \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse();
            }
        };

        $this->expectException(GrpcServiceConfigurationException::class);
        $this->expectExceptionMessageMatches('/string attribute/');

        GrpcRoutingTable::fromRegistry($this->buildRegistry('app.expr', $handler, EchoInterface::class));
    }

    public function testUnsupportedSubjectIsRejectedAtBuildTime(): void
    {
        $handler = new class extends GuardedEchoService {
            #[\Symfony\Component\Security\Http\Attribute\IsGranted('VIEW', 'somethingElse')]
            public function WhoAmI(\Spiral\RoadRunner\GRPC\ContextInterface $ctx, \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest $in): \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse
            {
                return new \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse();
            }
        };

        $this->expectException(GrpcServiceConfigurationException::class);

        GrpcRoutingTable::fromRegistry($this->buildRegistry('app.subject', $handler, EchoInterface::class));
    }

    public function testHandlerMethodAttributeWinsOverTheInterfaceAttribute(): void
    {
        $handler = new class extends GuardedEchoService {
            #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_OVERRIDE')]
            public function WhoAmI(\Spiral\RoadRunner\GRPC\ContextInterface $ctx, \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest $in): \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse
            {
                return new \FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse();
            }
        };

        $table = GrpcRoutingTable::fromRegistry($this->buildRegistry('app.override', $handler, EchoInterface::class));

        $route = $table->getRoute('bundle.test.Echo');
        self::assertNotNull($route);
        $attributes = $route->methods['WhoAmI']->accessAttributes;
        self::assertCount(1, $attributes);
        self::assertSame('ROLE_OVERRIDE', $attributes[0]->attribute);
    }

}
