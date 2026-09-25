<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAccessTokenAuthenticator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class GrpcUserCheckerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $authenticatorRegistered = $container->hasDefinition(GrpcAccessTokenAuthenticator::class);

        if (!$authenticatorRegistered) {
            return;
        }

        $authenticator = $container->getDefinition(GrpcAccessTokenAuthenticator::class);
        $firewallName = $authenticator->getArgument(7);

        if (!is_string($firewallName)) {
            throw new InvalidConfigurationException('fluffy_discord_road_runner.grpc.security.firewall_name must be a string');
        }

        $userCheckerId = $this->resolveUserCheckerId($container, $firewallName);
        $authenticator->replaceArgument(3, new Reference($userCheckerId));

        $this->reportResolvedUserChecker($container, $userCheckerId);
    }

    private function resolveUserCheckerId(ContainerBuilder $container, string $firewallName): string
    {
        $userCheckerId = 'security.user_checker.' . $firewallName;
        $userCheckerExists = $container->has($userCheckerId);

        if ($userCheckerExists) {
            return $userCheckerId;
        }

        throw new InvalidConfigurationException(sprintf(
            'fluffy_discord_road_runner.grpc.security.firewall_name "%s" matches no security firewall with a user checker, so gRPC calls would run no account-status checks (disabled and locked users would authenticate). Point it at a firewall declaring your user_checker%s',
            $firewallName,
            $this->describeUsableFirewalls($container),
        ));
    }

    private function describeUsableFirewalls(ContainerBuilder $container): string
    {
        $usableNames = $this->collectUsableFirewallNames($container);

        if ($usableNames === []) {
            return '; no firewall in this application declares one (a firewall with "security: false" does not qualify)';
        }

        return sprintf(' (usable firewalls: %s)', implode(', ', $usableNames));
    }

    /**
     * @return list<string>
     */
    private function collectUsableFirewallNames(ContainerBuilder $container): array
    {
        $parameterExists = $container->hasParameter('security.firewalls');

        if (!$parameterExists) {
            return [];
        }

        $firewallNames = $container->getParameter('security.firewalls');

        if (!is_array($firewallNames)) {
            return [];
        }

        $usableNames = [];

        foreach ($firewallNames as $firewallName) {
            if (!is_string($firewallName)) {
                continue;
            }

            $userCheckerExists = $container->has('security.user_checker.' . $firewallName);

            if ($userCheckerExists) {
                $usableNames[] = $firewallName;
            }
        }

        return $usableNames;
    }

    private function reportResolvedUserChecker(ContainerBuilder $container, string $userCheckerId): void
    {
        $introspectorRegistered = $container->hasDefinition(GrpcIntrospector::class);

        if (!$introspectorRegistered) {
            return;
        }

        $securityFacts = $container->getDefinition(GrpcIntrospector::class)->getArgument(2);

        if (!$securityFacts instanceof Definition) {
            return;
        }

        $securityFacts->replaceArgument(4, $userCheckerId);
    }
}
