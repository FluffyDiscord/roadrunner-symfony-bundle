<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Configuration;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends BaseTestCase
{
    private function processConfig(array $configs = [[]]): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }

    public function testDefaultValues(): void
    {
        $config = $this->processConfig();

        self::assertSame('.rr.yaml', $config['rr_config_path']);
        self::assertFalse($config['http']['lazy_boot']);
        self::assertFalse($config['http']['early_router_initialization']);
        self::assertFalse($config['centrifugo']['lazy_boot']);
        self::assertTrue($config['kv']['auto_register']);
        self::assertNull($config['kv']['serializer']);
        self::assertNull($config['kv']['keypair_path']);
        self::assertNull($config['jobs']['serializer']);
    }

    public function testCustomRrConfigPath(): void
    {
        $config = $this->processConfig([['rr_config_path' => '.rr.dev.yaml']]);

        self::assertSame('.rr.dev.yaml', $config['rr_config_path']);
    }

    public function testHttpLazyBootCanBeEnabled(): void
    {
        $config = $this->processConfig([['http' => ['lazy_boot' => true]]]);

        self::assertTrue($config['http']['lazy_boot']);
    }

    public function testEarlyRouterInitializationCanBeEnabled(): void
    {
        $config = $this->processConfig([['http' => ['early_router_initialization' => true]]]);

        self::assertTrue($config['http']['early_router_initialization']);
    }

    public function testCentrifugoLazyBootCanBeEnabled(): void
    {
        $config = $this->processConfig([['centrifugo' => ['lazy_boot' => true]]]);

        self::assertTrue($config['centrifugo']['lazy_boot']);
    }

    public function testKvAutoRegisterCanBeDisabled(): void
    {
        $config = $this->processConfig([['kv' => ['auto_register' => false]]]);

        self::assertFalse($config['kv']['auto_register']);
    }

    public function testKvSerializerCanBeSet(): void
    {
        $config = $this->processConfig([['kv' => ['serializer' => 'App\\CustomSerializer']]]);

        self::assertSame('App\\CustomSerializer', $config['kv']['serializer']);
    }

    public function testKvKeypairPathCanBeSet(): void
    {
        $config = $this->processConfig([['kv' => ['keypair_path' => 'config/keypair.bin']]]);

        self::assertSame('config/keypair.bin', $config['kv']['keypair_path']);
    }

    public function testJobsSerializerCanBeSet(): void
    {
        self::assertSame('igbinary', $this->processConfig([['jobs' => ['serializer' => 'igbinary']]])['jobs']['serializer']);
        self::assertSame('symfony', $this->processConfig([['jobs' => ['serializer' => 'symfony']]])['jobs']['serializer']);
        self::assertSame('native', $this->processConfig([['jobs' => ['serializer' => 'native']]])['jobs']['serializer']);
    }

    public function testJobsSerializerRejectsUnknownStrategy(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processConfig([['jobs' => ['serializer' => 'msgpack']]]);
    }

    public function testTreeBuilderRootNodeName(): void
    {
        $configuration = new Configuration();
        $treeBuilder = $configuration->getConfigTreeBuilder();

        self::assertSame('fluffy_discord_road_runner', $treeBuilder->buildTree()->getName());
    }

    public function testInfoFormattingInNonDumpMode(): void
    {
        unset($_SERVER['argv']);

        $configuration = new Configuration();
        $tree = $configuration->getConfigTreeBuilder()->buildTree();

        $info = $tree->getInfo();
        self::assertStringContainsString('https://github.com/FluffyDiscord/roadrunner-symfony-bundle', $info);
        self::assertStringNotContainsString('┌', $info);
    }

    public function testInfoFormattingInDumpMode(): void
    {
        $originalSelf = $_SERVER['PHP_SELF'] ?? null;
        $originalArgv = $_SERVER['argv'] ?? null;

        $_SERVER['PHP_SELF'] = 'bin/console';
        $_SERVER['argv'] = ['bin/console', 'config:dump-reference'];

        try {
            $configuration = new Configuration();
            $tree = $configuration->getConfigTreeBuilder()->buildTree();
            $info = $tree->getInfo();

            self::assertStringContainsString('┌', $info);
            self::assertStringContainsString('│', $info);
        } finally {
            if ($originalSelf === null) {
                unset($_SERVER['PHP_SELF']);
            } else {
                $_SERVER['PHP_SELF'] = $originalSelf;
            }
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }
}
