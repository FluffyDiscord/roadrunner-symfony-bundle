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

        // Cleanup failures are logged to STDERR (logError), not emitted as a second relay frame.
        $this->rrWorker->expects($this->never())->method('error');
        $this->rrWorker->expects($this->once())->method('stop');

        $worker = $this->makeWorker(kernel: $kernel);
        $worker->start();

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'Fatal worker cleanup error')),
            'cleanup failure should be logged to STDERR',
        );
    }
}
