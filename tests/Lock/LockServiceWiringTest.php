<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Lock;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use RoadRunner\Lock\Lock;
use Spiral\RoadRunner\Symfony\Lock\RoadRunnerStore;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * The optional RoadRunner lock bridge is wired by config/services.php when
 * roadrunner-php/symfony-lock-driver is installed: a Symfony LockFactory /
 * PersistingStoreInterface backed by RR's Lock plugin over the bundle's RPC.
 */
class LockServiceWiringTest extends BaseTestCase
{
    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        return $container;
    }

    public function testLockServicesAreRegisteredWhenDriverInstalled(): void
    {
        if (!class_exists(RoadRunnerStore::class)) {
            self::markTestSkipped('roadrunner-php/symfony-lock-driver is not installed.');
        }

        $container = $this->loadServices();

        self::assertTrue($container->hasDefinition(Lock::class));
        self::assertTrue($container->hasDefinition(RoadRunnerStore::class));
        self::assertTrue($container->hasDefinition('lock.factory'));

        self::assertSame(RoadRunnerStore::class, (string) $container->getAlias(PersistingStoreInterface::class));
        self::assertSame('lock.factory', (string) $container->getAlias(LockFactory::class));
    }

    public function testStoreWrapsTheRrLock(): void
    {
        if (!class_exists(RoadRunnerStore::class)) {
            self::markTestSkipped('roadrunner-php/symfony-lock-driver is not installed.');
        }

        $container = $this->loadServices();

        // RoadRunnerStore takes the RR Lock; the Lock takes the bundle's RPCInterface.
        $storeArgs = $container->getDefinition(RoadRunnerStore::class)->getArguments();
        self::assertSame(Lock::class, (string) $storeArgs[0]);
    }
}
