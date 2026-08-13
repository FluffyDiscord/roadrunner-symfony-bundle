<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerTerminateTest extends AbstractHttpWorkerTestCase
{
    public function testTerminateCalledOnSuccessWhenKernelIsTerminable(): void
    {
        $kernel = $this->createMock(TestKernelInterface::class);
        $kernel->method('handle')->willReturn(new Response());
        $kernel->expects($this->once())->method('terminate');

        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null)
        ;

        $this->makeWorker(kernel: $kernel)->start();
    }

    public function testTerminateNotCalledWhenKernelHandleThrows(): void
    {
        $kernel = $this->createMock(TestKernelInterface::class);
        $kernel->method('handle')->willThrowException(new \RuntimeException());
        $kernel->expects($this->never())->method('terminate');

        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null)
        ;

        $this->makeWorker(kernel: $kernel)->start();
    }

    public function testTerminateNotCalledWhenKernelIsNotTerminable(): void
    {
        $this->setupSuccessfulRequest();

        $this->addToAssertionCount(1);
        $this->makeWorker()->start();
    }
}
