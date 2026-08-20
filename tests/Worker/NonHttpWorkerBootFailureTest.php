<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Bucket D2 on a worker with no HTTP client: the failure is logged and reported, and the
 * consume loop still runs (worker-warmup ADR-5 — degrade, never "no worker").
 *
 * @see docs/specs/graceful-error-handling.md §6.5, §6.9 (TC-D10)
 */
#[AllowMockObjectsWithoutExpectations]
class NonHttpWorkerBootFailureTest extends AbstractJobsWorkerTestCase
{
    use FailingBootListener;

    /** TC-D10 */
    public function testJobsWorkerKeepsConsumingAfterABootListenerFailure(): void
    {
        $this->failBootListener();

        $task = $this->makeTask();
        $worker = $this->makeWorker([$task]);
        $worker->start();

        $this->assertSame(1, $task->ackCount, 'the consume loop must still run after a failed boot listener');
        $this->assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
    }

    /** TC-D10: the failure reaches Sentry — the container survived. */
    public function testJobsWorkerReportsTheBootFailureToSentry(): void
    {
        $this->failBootListener();

        $sentryHub = $this->createMock(\Sentry\State\HubInterface::class);
        $sentryHub->method('pushScope')->willReturn(new \Sentry\State\Scope());
        $sentryHub->expects($this->once())->method('captureException');

        $this->makeWorker([], $sentryHub)->start();
    }
}
