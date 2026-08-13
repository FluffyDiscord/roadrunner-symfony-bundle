<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\RequestFactoryPass;
use FluffyDiscord\RoadRunnerBundle\Factory\NativeSymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\SymfonyRequestFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class RequestFactoryPassTest extends BaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: bool, 2: class-string}>
     */
    public static function resolutionTruthTable(): array
    {
        return [
            'auto without custom factory -> native' => ['auto', false, NativeSymfonyRequestFactory::class],
            'auto with custom factory -> psr7'      => ['auto', true, Psr7SymfonyRequestFactory::class],
            'explicit native wins over factory'     => ['native', true, NativeSymfonyRequestFactory::class],
            'explicit psr7 without factory'         => ['psr7', false, Psr7SymfonyRequestFactory::class],
        ];
    }

    #[DataProvider('resolutionTruthTable')]
    public function testResolution(string $configuredMode, bool $customFactoryWired, string $expectedClass): void
    {
        $container = $this->makeContainer($configuredMode, $customFactoryWired);

        new RequestFactoryPass()->process($container);

        $alias = $container->getAlias(SymfonyRequestFactoryInterface::class);
        $this->assertSame($expectedClass, (string) $alias);
        $this->assertSame($expectedClass, $container->getParameter('fluffy_discord.http.request_factory.resolved'));
    }

    public function testMissingConfiguredModeParameterDefaultsToAuto(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NativeSymfonyRequestFactory::class, new Definition(NativeSymfonyRequestFactory::class));
        $container->setDefinition(Psr7SymfonyRequestFactory::class, new Definition(Psr7SymfonyRequestFactory::class));

        new RequestFactoryPass()->process($container);

        $alias = $container->getAlias(SymfonyRequestFactoryInterface::class);
        $this->assertSame(NativeSymfonyRequestFactory::class, (string) $alias);
    }

    public function testPassIsNoOpWhenFactoriesAreNotRegistered(): void
    {
        $container = new ContainerBuilder();

        new RequestFactoryPass()->process($container);

        $this->assertFalse($container->hasAlias(SymfonyRequestFactoryInterface::class));
        $this->assertFalse($container->hasParameter('fluffy_discord.http.request_factory.resolved'));
    }

    private function makeContainer(string $configuredMode, bool $customFactoryWired): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('fluffy_discord.http.request_factory', $configuredMode);
        $container->setDefinition(NativeSymfonyRequestFactory::class, new Definition(NativeSymfonyRequestFactory::class));
        $container->setDefinition(Psr7SymfonyRequestFactory::class, new Definition(Psr7SymfonyRequestFactory::class));

        if ($customFactoryWired) {
            $container->setDefinition(HttpFoundationFactoryInterface::class, new Definition(HttpFoundationFactory::class));
        }

        return $container;
    }
}
