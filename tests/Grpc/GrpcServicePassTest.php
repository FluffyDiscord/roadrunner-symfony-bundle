<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\GrpcServicePass;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceDuplicateNameException;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceInterfaceMissingException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\BareServiceInterfaceService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\ExtendedEchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** TC-01 / TC-02 */
class GrpcServicePassTest extends BaseTestCase
{
    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $registry = new Definition(GrpcServiceRegistry::class, [null]);
        $container->setDefinition(GrpcServiceRegistry::class, $registry);

        return $container;
    }

    /**
     * @return list<array{string, array<int, mixed>}>
     */
    private function addServiceCalls(ContainerBuilder $container): array
    {
        return $container->getDefinition(GrpcServiceRegistry::class)->getMethodCalls();
    }

    public function testTaggedEchoServiceIsRecordedWithItsGeneratedInterface(): void
    {
        $container = $this->buildContainer();
        $container->register('app.echo', EchoService::class)->addTag(GrpcServicePass::TAG);

        new GrpcServicePass()->process($container);

        self::assertSame([['addService', [EchoInterface::class, 'app.echo', EchoService::class]]], $this->addServiceCalls($container));
    }

    public function testInterfaceExtendingAGeneratedOneIsNotRecordedTwice(): void
    {
        $container = $this->buildContainer();
        $container->register('app.extended_echo', ExtendedEchoService::class)->addTag(GrpcServicePass::TAG);

        new GrpcServicePass()->process($container);

        self::assertSame([['addService', [EchoInterface::class, 'app.extended_echo', ExtendedEchoService::class]]], $this->addServiceCalls($container));
    }

    public function testTwoServicesSharingOneNameFailAtCompileTime(): void
    {
        $container = $this->buildContainer();
        $container->register('app.echo', EchoService::class)->addTag(GrpcServicePass::TAG);
        $container->register('app.echo_two', ExtendedEchoService::class)->addTag(GrpcServicePass::TAG);

        $this->expectException(GrpcServiceDuplicateNameException::class);

        new GrpcServicePass()->process($container);
    }

    public function testBareServiceInterfaceImplementationFailsAtCompileTime(): void
    {
        $container = $this->buildContainer();
        $container->register('app.bare', BareServiceInterfaceService::class)->addTag(GrpcServicePass::TAG);

        $this->expectException(GrpcServiceInterfaceMissingException::class);

        new GrpcServicePass()->process($container);
    }

    public function testAbstractDefinitionsAreSkipped(): void
    {
        $container = $this->buildContainer();
        $container->register('app.abstract', EchoService::class)->setAbstract(true)->addTag(GrpcServicePass::TAG);

        new GrpcServicePass()->process($container);

        self::assertSame([], $this->addServiceCalls($container));
    }
}
