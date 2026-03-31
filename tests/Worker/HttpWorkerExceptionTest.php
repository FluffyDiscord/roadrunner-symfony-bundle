<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerExceptionTest extends AbstractHttpWorkerTestCase
{
    public function testKernelExceptionResponds500WithoutBodyInProdMode(): void
    {
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException(new \RuntimeException('boom'));

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                fn($r) => $r->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && (string)$r->getBody() === '',
            ))
        ;

        $this->rrWorker->expects($this->once())->method('error');

        $this->makeWorker(debug: false)->start();
    }

    public function testKernelExceptionResponds500WithBodyInDebugMode(): void
    {
        $exception = new \RuntimeException('debug info');

        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException($exception);

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                fn($r) => $r->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && str_contains((string)$r->getBody(), 'debug info')
                    && str_contains((string)$r->getBody(), 'RuntimeException'),
            ))
        ;

        $this->makeWorker(debug: true)->start();
    }

    public function testErrorCallsWorkerStop(): void
    {
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException(new \Error('fatal'));

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker()->start();
    }

    public function testExceptionDoesNotCallWorkerStop(): void
    {
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException(new \RuntimeException('soft error'));

        $this->rrWorker->expects($this->never())->method('stop');

        $this->makeWorker()->start();
    }
}
