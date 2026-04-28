<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class WorkerRegistryTest extends BaseTestCase
{
    public function testGetWorkerReturnsNullForUnknownMode(): void
    {
        $registry = new WorkerRegistry();

        self::assertNull($registry->getWorker('http'));
    }

    public function testRegisterAndRetrieveWorker(): void
    {
        $registry = new WorkerRegistry();
        $worker = $this->createMock(WorkerInterface::class);

        $registry->registerWorker('http', $worker);

        self::assertSame($worker, $registry->getWorker('http'));
    }

    public function testOverwriteWorkerForSameMode(): void
    {
        $registry = new WorkerRegistry();
        $first = $this->createMock(WorkerInterface::class);
        $second = $this->createMock(WorkerInterface::class);

        $registry->registerWorker('http', $first);
        $registry->registerWorker('http', $second);

        self::assertSame($second, $registry->getWorker('http'));
    }

    public function testMultipleModesAreIndependent(): void
    {
        $registry = new WorkerRegistry();
        $httpWorker = $this->createMock(WorkerInterface::class);
        $centrifugoWorker = $this->createMock(WorkerInterface::class);

        $registry->registerWorker('http', $httpWorker);
        $registry->registerWorker('centrifuge', $centrifugoWorker);

        self::assertSame($httpWorker, $registry->getWorker('http'));
        self::assertSame($centrifugoWorker, $registry->getWorker('centrifuge'));
    }
}
