<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Temporal\Worker\WorkerInterface;

/**
 * The runtime registry of instantiated SDK workers, keyed by task queue.
 */
#[AllowMockObjectsWithoutExpectations]
class TemporalWorkerRegistryTest extends BaseTestCase
{
    public function testAddGetHasAndAll(): void
    {
        $registry = new TemporalWorkerRegistry();
        $default = $this->createMock(WorkerInterface::class);
        $billing = $this->createMock(WorkerInterface::class);

        $registry->add('default', $default);
        $registry->add('billing', $billing);

        self::assertTrue($registry->has('default'));
        self::assertSame($default, $registry->get('default'));
        self::assertSame($billing, $registry->get('billing'));
        self::assertSame(['default' => $default, 'billing' => $billing], $registry->all());
    }

    public function testMissingQueue(): void
    {
        $registry = new TemporalWorkerRegistry();

        self::assertFalse($registry->has('nope'));
        self::assertNull($registry->get('nope'));
        self::assertSame([], $registry->all());
    }
}
