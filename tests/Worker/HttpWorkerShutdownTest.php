<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bucket B / B′ — the register_shutdown_function path for die()/exit()/fatal.
 *
 * @see \FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::handleShutdown()
 * @see docs/specs/graceful-error-handling.md §N-2 (TC-01..06, TC-12, IT-03)
 */
#[AllowMockObjectsWithoutExpectations]
class HttpWorkerShutdownTest extends AbstractHttpWorkerTestCase
{
    /** TC-01: debug fatal with an error array → one HTML page frame, no error() frame. */
    public function testDebugFatalSendsMinimalPageOnce(): void
    {
        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->callback(static fn(string $body): bool => str_contains($body, 'Boom fatal') && str_contains($body, '500')),
                $this->callback(static fn(array $headers): bool => ($headers['Content-Type'][0] ?? null) === 'text/html; charset=utf-8'),
                true,
            )
        ;
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown($this->psr7Worker, true, false, [
            'message' => 'Boom fatal',
            'file'    => '/app/src/Controller.php',
            'line'    => 42,
        ]);
    }

    /** TC-02: bare die()/exit() leaves error_get_last()===null → generic page + generic log. */
    public function testBareDieWithNullErrorSendsGenericPage(): void
    {
        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->callback(static fn(string $body): bool => str_contains($body, 'terminated') && str_contains($body, '500')),
                $this->anything(),
                true,
            )
        ;

        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown($this->psr7Worker, true, false, null);

        $this->assertNotEmpty(
            array_filter($worker->loggedErrors, static fn(string $m): bool => str_contains($m, 'die/exit')),
        );
    }

    /** TC-03: prod fatal → empty 500 body, no information disclosure. */
    public function testProdFatalSendsEmptyBody(): void
    {
        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(Response::HTTP_INTERNAL_SERVER_ERROR, '', [], true)
        ;

        $worker = $this->makeWorker(debug: false);
        $worker->callHandleShutdown($this->psr7Worker, true, false, [
            'message' => 'secret stacktrace detail',
            'file'    => '/app/x.php',
            'line'    => 1,
        ]);
    }

    /** TC-04 / IT-03: a response already started (e.g. mid-stream die) → no added frame. */
    public function testNoFrameWhenResponseAlreadyStarted(): void
    {
        $this->spiralHttpWorker->expects($this->never())->method('respond');
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown($this->psr7Worker, true, true, ['message' => 'mid-stream', 'file' => 'f', 'line' => 1]);
    }

    /** TC-04: not handling a request (normal exit / boot-time death) → no-op. */
    public function testNoFrameWhenNotHandlingRequest(): void
    {
        $this->spiralHttpWorker->expects($this->never())->method('respond');
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown($this->psr7Worker, false, false, null);
    }

    /** TC-05: relay write fails → fall back to a single error() frame, nothing escapes. */
    public function testFallsBackToErrorFrameWhenRespondThrows(): void
    {
        $this->spiralHttpWorker->method('respond')->willThrowException(new \RuntimeException('relay dead'));
        $this->rrWorker->expects($this->once())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown($this->psr7Worker, true, false, ['message' => 'orig fatal', 'file' => 'f', 'line' => 1]);
    }

    /** TC-05 edge: even if BOTH respond() and the error() fallback throw, nothing escapes. */
    public function testNothingEscapesWhenRespondAndErrorBothThrow(): void
    {
        $this->spiralHttpWorker->method('respond')->willThrowException(new \RuntimeException('relay dead'));
        $this->rrWorker->method('error')->willThrowException(new \RuntimeException('error frame dead too'));

        $worker = $this->makeWorker(debug: true);
        // Must complete without throwing despite both sinks failing.
        $worker->callHandleShutdown($this->psr7Worker, true, false, ['message' => 'orig', 'file' => 'f', 'line' => 1]);

        $this->assertNotEmpty($worker->loggedErrors, 'the fatal is still logged to STDERR');
    }

    /** TC-06: an OOM fatal lifts PHP's memory_limit *before* the page is rendered. */
    public function testOomLiftsMemoryLimitBeforeRender(): void
    {
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '128M');

        // capture the limit at the moment respond() is invoked — its body arg (the rendered page)
        // is built AFTER the ini_set, so a value of -1 here proves the cap was lifted first.
        $limitAtRespondTime = null;
        $this->spiralHttpWorker->method('respond')
            ->willReturnCallback(function () use (&$limitAtRespondTime): void {
                $limitAtRespondTime = ini_get('memory_limit');
            });

        try {
            $worker = $this->makeWorker(debug: true);
            $worker->callHandleShutdown($this->psr7Worker, true, false, [
                'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 1048576 bytes)',
                'file'    => '/app/x.php',
                'line'    => 7,
            ]);

            $this->assertSame('-1', $limitAtRespondTime);
        } finally {
            ini_set('memory_limit', $original === false ? '-1' : $original);
        }
    }

    /** Bucket B reports to Sentry best-effort, since the finally-block flush never runs. */
    public function testFatalIsReportedToSentryBestEffort(): void
    {
        $hub = $this->makeSentryHubMock();
        $hub->expects($this->once())->method('captureMessage');

        $worker = $this->makeWorker(debug: false, sentryHub: $hub);
        $worker->callHandleShutdown($this->psr7Worker, true, false, ['message' => 'fatal x', 'file' => 'f', 'line' => 1]);
    }

    /** TC-12: the shutdown handler is registered at most once per worker instance. */
    public function testShutdownRegisteredOncePerInstance(): void
    {
        $this->psr7Worker->method('waitRequest')->willReturn(null);

        $worker = $this->makeWorker();
        $worker->start();
        $worker->start();

        $this->assertSame(1, $worker->shutdownRegistrations);
        $this->assertInstanceOf(\Closure::class, $worker->registeredShutdown);
    }
}
