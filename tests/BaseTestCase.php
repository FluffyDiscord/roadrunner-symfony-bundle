<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Http\InformationalHeaders;
use FluffyDiscord\RoadRunnerBundle\Tests\Attributes\SkipForSymfonyVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

abstract class BaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionMethod($this, $this->name());

        foreach ($reflection->getAttributes(SkipForSymfonyVersion::class) as $attribute) {
            $instance = $attribute->newInstance();
            assert($instance instanceof SkipForSymfonyVersion);

            if ($instance->shouldSkip()) {
                $this->markTestSkipped("Skipping test for Symfony $instance->operator $instance->version");
            }
        }
    }

    protected function tearDown(): void
    {
        InformationalHeaders::forgetSentHeaders();
        putenv('RR_VERSION');

        parent::tearDown();
    }

    protected function loadBundleServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../config'));
        $loader->load('services.php');

        return $container;
    }
}