<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bucket D2 — a boot listener dies while the kernel itself is serviceable.
 *
 * @see docs/specs/graceful-error-handling.md §6.5, §6.9 (TC-D05, TC-D06)
 */
#[AllowMockObjectsWithoutExpectations]
class HttpWorkerBootFailureTest extends AbstractHttpWorkerTestCase
{
    private function failBootListener(string $message = 'boot listener exploded'): void
    {
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($message): object {
                if ($event instanceof WorkerBootingEvent) {
                    throw new \RuntimeException($message);
                }

                return $event;
            })
        ;
    }

    /** TC-D05 */
    public function testDebugServesTheBootFailurePageForOneRequestThenReturns(): void
    {
        $this->failBootListener();
        $this->spiralHttpWorker->expects($this->once())->method('waitRequest')->willReturn($this->rrRequest());
        $this->kernel->expects($this->never())->method('handle');

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && str_contains((string)$response->getBody(), 'boot listener exploded'),
            ))
        ;

        $this->rrWorker->expects($this->never())->method('stop');
        $this->rrWorker->expects($this->never())->method('error');

        $worker = $this->makeWorker(debug: true);
        $worker->start();

        $this->assertSame(0, $worker->shutdownRegistrations, 'no shutdown rescue may be registered on the boot-failure path');
        $this->assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
    }

    /** TC-D05 edge: RR stops the worker before delivering a request → no frame at all. */
    public function testDebugSendsNoFrameWhenNoRequestArrives(): void
    {
        $this->failBootListener();
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);
        $this->psr7Worker->expects($this->never())->method('respond');

        $this->makeWorker(debug: true)->start();
    }

    /** TC-D06 */
    public function testProductionLogsAndKeepsServing(): void
    {
        $this->failBootListener();
        $this->spiralHttpWorker->method('waitRequest')->willReturnOnConsecutiveCalls($this->rrRequest(), null);
        $this->kernel->method('handle')->willReturn(new Response('ok', Response::HTTP_OK));

        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(Response::HTTP_OK, 'ok', $this->anything())
        ;

        $worker = $this->makeWorker(debug: false);
        $worker->start();

        $this->assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
    }

    /** TC-D06: the failure is reported to Sentry — the container survived, so a hub exists. */
    public function testBootFailureIsCapturedBySentry(): void
    {
        $this->failBootListener();
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);

        $sentryHub = $this->makeSentryHubMock();
        $sentryHub->expects($this->once())->method('captureException');

        $this->makeWorker(debug: false, sentryHub: $sentryHub)->start();
    }
}
