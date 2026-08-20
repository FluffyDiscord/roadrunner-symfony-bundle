<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * @see docs/specs/graceful-error-handling.md §6.5, §6.9 (TC-D10)
 */
#[AllowMockObjectsWithoutExpectations]
class CentrifugoWorkerBootFailureTest extends AbstractCentrifugoWorkerTestCase
{
    use FailingBootListener;

    /** TC-D10 */
    public function testCentrifugoWorkerKeepsConsumingAfterABootListenerFailure(): void
    {
        $this->failBootListener();

        $worker = $this->makeWorker(requests: [$this->makeConnect()]);
        $worker->start();

        $this->assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
    }

    /** TC-D10: identical in debug — no page exists to render for an RPC worker. */
    public function testCentrifugoWorkerBehavesIdenticallyInDebug(): void
    {
        $this->failBootListener();

        $worker = $this->makeWorker(debug: true);
        $worker->start();

        $this->assertStringContainsString('BOOT FAILURE', implode("\n", $worker->loggedErrors));
    }
}
