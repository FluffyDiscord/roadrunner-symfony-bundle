<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Configuration;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAccessTokenAuthenticator;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface;
use FluffyDiscord\RoadRunnerBundle\Grpc\Tracing\GrpcTracingListener;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** TC-24 */
class GrpcConfigurationTest extends BaseTestCase
{
    /**
     * @param array<string, mixed> $bundleConfig
     * @return array<string, mixed>
     */
    private function processConfiguration(array $bundleConfig): array
    {
        return new Processor()->processConfiguration(new Configuration(), ['fluffy_discord_road_runner' => $bundleConfig]);
    }

    public function testGrpcDefaults(): void
    {
        $config = $this->processConfiguration([]);

        self::assertFalse($config['grpc']['tracing']);
        self::assertFalse($config['grpc']['security']['enabled']);
        self::assertSame('authorization', $config['grpc']['security']['metadata_key']);
        self::assertSame('Bearer ', $config['grpc']['security']['token_prefix']);
        self::assertTrue($config['grpc']['security']['required']);
        self::assertSame('grpc', $config['grpc']['security']['firewall_name']);
        self::assertSame(['authorization', 'proxy-authorization', 'cookie'], $config['grpc']['profiler']['redacted_metadata_keys']);
    }

    public function testSecurityEnabledWithoutTokenHandlerIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/token_handler is required/');

        $this->processConfiguration(['grpc' => ['security' => ['enabled' => true]]]);
    }

    private function loadExtension(array $bundleConfig, bool $withSecurityBundle = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/../Temporal/Fixtures');
        $bundleConfig['rr_config_path'] = 'temporal.rr.yaml';

        $registeredBundles = $withSecurityBundle ? ['SecurityBundle' => \Symfony\Bundle\SecurityBundle\SecurityBundle::class] : [];
        $container->setParameter('kernel.bundles', $registeredBundles);

        new FluffyDiscordRoadRunnerExtension()->load([$bundleConfig], $container);

        return $container;
    }

    public function testTracingRegistersTheListenerWithThreeEventTags(): void
    {
        $container = $this->loadExtension(['grpc' => ['tracing' => true]]);

        self::assertTrue($container->hasDefinition(GrpcTracingListener::class));
        self::assertCount(3, $container->getDefinition(GrpcTracingListener::class)->getTag('kernel.event_listener'));
    }

    public function testTracingOffRegistersNoListener(): void
    {
        $container = $this->loadExtension([]);

        self::assertFalse($container->hasDefinition(GrpcTracingListener::class));
    }

    public function testSecurityEnabledRegistersTheAuthenticatorAndGuard(): void
    {
        $container = $this->loadExtension(['grpc' => ['security' => ['enabled' => true, 'token_handler' => 'app.token_handler']]]);

        self::assertTrue($container->hasDefinition(GrpcAccessTokenAuthenticator::class));
        self::assertTrue($container->hasAlias(GrpcCallAuthenticatorInterface::class));
        $securityFactsDefinition = $container->getDefinition(\FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector::class)->getArgument(2);
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $securityFactsDefinition);
        self::assertTrue($securityFactsDefinition->getArgument(0));
        self::assertSame('app.token_handler', $securityFactsDefinition->getArgument(1));
        $configReaderPath = $container->getDefinition(\FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader::class)->getArgument(1);
        self::assertSame('temporal.rr.yaml', $configReaderPath);
    }

    public function testSecurityEnabledWithoutTheSecurityExtensionFailsWithGuidance(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/requires symfony\/security-bundle/');

        $this->loadExtension(['grpc' => ['security' => ['enabled' => true, 'token_handler' => 'app.token_handler']]], withSecurityBundle: false);
    }

    public function testSecurityDisabledRegistersNothing(): void
    {
        $container = $this->loadExtension([]);

        self::assertFalse($container->hasDefinition(GrpcAccessTokenAuthenticator::class));
        $securityFactsDefinition = $container->getDefinition(\FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector::class)->getArgument(2);
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $securityFactsDefinition);
        self::assertFalse($securityFactsDefinition->getArgument(0));
    }

    public function testPrependAddsTheGrpcMonologChannel(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends \Symfony\Component\DependencyInjection\Extension\Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'monolog';
            }
        });

        new FluffyDiscordRoadRunnerExtension()->prepend($container);

        $channels = array_column(array_merge(...array_values($container->getExtensionConfig('monolog') === [] ? [[]] : [$container->getExtensionConfig('monolog')])), 'channels');
        self::assertContains(['grpc'], $channels);
    }

}
