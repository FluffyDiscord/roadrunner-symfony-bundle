<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use RoadRunner\Lock\Lock;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Symfony\Lock\RoadRunnerStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

// Distributed locks: exposes RR's Lock plugin as a Symfony LockFactory when the bridge is installed.
return static function (ContainerConfigurator $container): void {
    if (!class_exists(RoadRunnerStore::class)) {
        return;
    }

    $services = $container->services();

    $services
        ->set(Lock::class)
        ->args([
            service(RPCInterface::class),
        ])
    ;

    $services
        ->set(RoadRunnerStore::class)
        ->args([
            service(Lock::class),
        ])
    ;
    $services->alias(PersistingStoreInterface::class, RoadRunnerStore::class);

    $services
        ->set("lock.factory", LockFactory::class)
        ->args([
            service(RoadRunnerStore::class),
        ])
    ;
    $services->alias(LockFactory::class, "lock.factory");
};
