<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class CentrifugoWorkerBootTest extends AbstractCentrifugoWorkerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            static fn (object $event): object => $event,
        );
    }

    public function testLazyBootBootsExactlyOnceAcrossTwoEvents(): void
    {
        $this->kernel->expects($this->once())->method('boot');

        $worker = $this->makeWorker(requests: [$this->makeConnect(), $this->makeConnect()]);
        $worker->start();
    }

    public function testEagerBootBootsBeforeLoopAndNeverInsideIt(): void
    {
        $this->kernel->expects($this->once())->method('boot');

        $worker = new TestableCentrifugoWorker(
            lazyBoot: false,
            debug: false,
            kernel: $this->kernel,
            worker: $this->centrifugoWorker,
            eventDispatcher: $this->eventDispatcher,
            servicesResetter: $this->servicesResetter,
        );
        $worker->requestQueue = [$this->makeConnect(), $this->makeConnect()];
        $worker->start();
    }

    public function testServicesAreResetBetweenEventsAfterBootRemoval(): void
    {
        $this->kernel->method('boot');
        $this->servicesResetter->expects($this->exactly(2))->method('reset');

        $worker = $this->makeWorker(requests: [$this->makeConnect(), $this->makeConnect()]);
        $worker->start();
    }
}
