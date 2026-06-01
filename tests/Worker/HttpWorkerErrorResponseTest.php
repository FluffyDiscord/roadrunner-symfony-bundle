<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bucket A — the catch-block error response (Symfony page in debug, bare 500 in prod,
 * MinimalErrorPage fallback if the renderer itself fails), and the one-frame guarantee.
 *
 * @see \FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::sendThrowableResponse()
 * @see docs/specs/graceful-error-handling.md §N-2 (TC-07..10, IT-02)
 */
#[AllowMockObjectsWithoutExpectations]
class HttpWorkerErrorResponseTest extends AbstractHttpWorkerTestCase
{
    /** TC-07: debug → rich Symfony page once; no error() frame. */
    public function testDebugSendsSymfonyPageOnce(): void
    {
        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($r): bool => $r->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && str_contains((string) $r->getBody(), 'RuntimeException')
                    && str_contains((string) $r->getBody(), 'kaboom'),
            ))
        ;
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->callSendThrowableResponse($this->psr7Worker, new \RuntimeException('kaboom'));
    }

    /** TC-08: debug, the Symfony renderer itself fails → MinimalErrorPage fallback (not a raw string). */
    public function testDebugFallsBackToMinimalPageWhenRendererFails(): void
    {
        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($r): bool => $r->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && $r->getHeaderLine('Content-Type') === 'text/html; charset=utf-8'
                    && str_contains((string) $r->getBody(), 'Internal Server Error')
                    && str_contains((string) $r->getBody(), 'kaboom'),
            ))
        ;
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->failHtmlRenderer = true;
        $worker->callSendThrowableResponse($this->psr7Worker, new \RuntimeException('kaboom'));
    }

    /** TC-09: prod → empty 500 body. */
    public function testProdSendsEmptyBody(): void
    {
        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($r): bool => $r->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && (string) $r->getBody() === '',
            ))
        ;

        $worker = $this->makeWorker(debug: false);
        $worker->callSendThrowableResponse($this->psr7Worker, new \RuntimeException('hidden detail'));
    }

    /** IT-02 / TC-10: a thrown request emits exactly one response frame and zero error() frames. */
    public function testCatchPathEmitsSingleFrameAndLogsToStderr(): void
    {
        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException(new \RuntimeException('only once'));

        $this->psr7Worker->expects($this->once())->method('respond');
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->start();

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'only once')),
        );
    }
}
