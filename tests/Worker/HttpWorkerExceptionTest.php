<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerExceptionTest extends AbstractHttpWorkerTestCase
{
    public function testKernelExceptionResponds500WithoutBodyInProdMode(): void
    {
        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null)
        ;
        $this->kernel->method('handle')->willThrowException(new \RuntimeException('boom'));

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                fn($r) => $r->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && (string)$r->getBody() === '',
            ))
        ;

        // One frame per request: the throwable goes to STDERR (logError), NOT a second error() relay frame.
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: false);
        $worker->start();

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'boom')),
            'the throwable should be logged to STDERR',
        );
    }

    public function testKernelExceptionResponds500WithBodyInDebugMode(): void
    {
        $exception = new \RuntimeException('debug info');

        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null)
        ;
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

        // Even when a rich debug page is sent, no second error() frame is emitted.
        $this->rrWorker->expects($this->never())->method('error');

        $this->makeWorker(debug: true)->start();
    }

    public function testErrorCallsWorkerStop(): void
    {
        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null)
        ;
        $this->kernel->method('handle')->willThrowException(new \Error('fatal'));

        $this->rrWorker->expects($this->atLeastOnce())->method('stop');

        $this->makeWorker()->start();
    }

    public function testExceptionDoesNotCallWorkerStop(): void
    {
        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->rrRequest(), null)
        ;
        $this->kernel->method('handle')->willThrowException(new \RuntimeException('soft error'));

        $this->rrWorker->expects($this->never())->method('stop');

        $this->makeWorker()->start();
    }
}
