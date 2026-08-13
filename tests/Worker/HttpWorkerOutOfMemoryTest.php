<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerOutOfMemoryTest extends AbstractHttpWorkerTestCase
{
    public function testOutOfMemoryErrorFromKernelHandleStopsWorker(): void
    {
        $oom = self::makeOutOfMemoryError();

        $this->spiralHttpWorker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null);

        $this->kernel->method('handle')
            ->willReturnCallback(static function () use ($oom): void { throw $oom; });

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker()->start();
    }

    public function testOutOfMemoryErrorWithSecondaryOOMDuringCleanupStillStopsWorker(): void
    {
        $firstOom  = self::makeOutOfMemoryError();
        $secondOom = self::makeOutOfMemoryError();

        $this->spiralHttpWorker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null);

        $this->kernel->method('handle')
            ->willReturnCallback(static function () use ($firstOom): void { throw $firstOom; });

        $this->servicesResetter->method('reset')
            ->willReturnCallback(static function () use ($secondOom): void { throw $secondOom; });

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker()->start();
    }
}
