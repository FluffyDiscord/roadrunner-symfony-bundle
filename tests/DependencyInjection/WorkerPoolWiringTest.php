<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\DependencyInjection;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\WorkerPool;

class WorkerPoolWiringTest extends BaseTestCase
{
    public function testWorkerPoolUsesTheBundleRpc(): void
    {
        $container = $this->loadBundleServices();

        $workerPoolArguments = $container->getDefinition(WorkerPool::class)->getArguments();

        self::assertSame(RPCInterface::class, (string) $workerPoolArguments[0]);
    }
}
