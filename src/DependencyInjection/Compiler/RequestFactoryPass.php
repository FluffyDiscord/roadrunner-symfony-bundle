<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Factory\NativeSymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\SymfonyRequestFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RequestFactoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $nativeFactoryIsRegistered = $container->hasDefinition(NativeSymfonyRequestFactory::class);
        if (!$nativeFactoryIsRegistered) {
            return;
        }

        $configuredMode = 'auto';
        $configuredModeParameterExists = $container->hasParameter('fluffy_discord.http.request_factory');
        if ($configuredModeParameterExists) {
            $configuredModeParameter = $container->getParameter('fluffy_discord.http.request_factory');
            $configuredMode = is_string($configuredModeParameter) ? $configuredModeParameter : 'auto';
        }

        $resolvedMode = $configuredMode;
        if ($configuredMode === 'auto') {
            $customHttpFoundationFactoryIsWired = $container->has(HttpFoundationFactoryInterface::class);
            $resolvedMode = $customHttpFoundationFactoryIsWired ? 'psr7' : 'native';
        }

        $resolvedClass = $resolvedMode === 'psr7' ? Psr7SymfonyRequestFactory::class : NativeSymfonyRequestFactory::class;

        $container->setAlias(SymfonyRequestFactoryInterface::class, $resolvedClass);
        $container->setParameter('fluffy_discord.http.request_factory.resolved', $resolvedClass);
    }
}
