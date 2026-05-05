<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerOutOfMemoryTest extends AbstractHttpWorkerTestCase
{
    public function testOutOfMemoryErrorFromKernelHandleStopsWorker(): void
    {
        $oom = self::makeOutOfMemoryError();

        $this->psr7Worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null);
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->kernel->method('handle')
            ->willReturnCallback(static function () use ($oom): void { throw $oom; });

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker()->start();
    }

    public function testOutOfMemoryErrorWithSecondaryOOMDuringCleanupStillStopsWorker(): void
    {
        $firstOom  = self::makeOutOfMemoryError();
        $secondOom = self::makeOutOfMemoryError();

        $this->psr7Worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null);
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->kernel->method('handle')
            ->willReturnCallback(static function () use ($firstOom): void { throw $firstOom; });

        $this->servicesResetter->method('reset')
            ->willReturnCallback(static function () use ($secondOom): void { throw $secondOom; });

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker()->start();
    }
}
