<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerRebootResetTest extends AbstractHttpWorkerTestCase
{
    public function testKernelRebootCalledOnExceptionWhenRebootable(): void
    {
        $kernel = $this->createMock(TestKernelInterface::class);
        $kernel->method('handle')->willThrowException(new \RuntimeException('err'));
        $kernel->expects($this->once())->method('reboot')->with(null);

        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->makeWorker(kernel: $kernel)->start();
    }

    public function testKernelRebootNotCalledOnSuccessfulRequest(): void
    {
        $kernel = $this->createMock(TestKernelInterface::class);
        $kernel->method('handle')->willReturn(new Response());
        $kernel->expects($this->never())->method('reboot');

        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->makeWorker(kernel: $kernel)->start();
    }

    public function testServicesResetterCalledInProductionMode(): void
    {
        $this->setupSuccessfulRequest();

        $this->servicesResetter->expects($this->once())->method('reset');

        $this->makeWorker(debug: false)->start();
    }

    public function testServicesResetterCalledEvenOnExceptionInProductionMode(): void
    {
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException(new \RuntimeException());

        $this->servicesResetter->expects($this->once())->method('reset');

        $this->makeWorker(debug: false)->start();
    }

    public function testServicesResetterCalledInDebugModeAsWell(): void
    {
        $this->setupSuccessfulRequest();

        $this->servicesResetter->expects($this->once())->method('reset');

        $this->makeWorker(debug: true)->start();
    }

    public function testServicesResetterExceptionStopsWorker(): void
    {
        $this->setupSuccessfulRequest();

        $this->servicesResetter
            ->method('reset')
            ->willThrowException(new \RuntimeException('reset failed'))
        ;

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker(debug: false)->start();
    }

    public function testCleanupTerminateExceptionLogsErrorAndStopsWorker(): void
    {
        $kernel = $this->createMock(TestKernelInterface::class);
        $kernel->method('handle')->willReturn(new Response());
        $kernel->method('terminate')->willThrowException(new \RuntimeException('cleanup failure'));

        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->rrWorker
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Fatal worker cleanup error'))
        ;
        $this->rrWorker->expects($this->once())->method('stop');

        $this->makeWorker(kernel: $kernel)->start();
    }
}
