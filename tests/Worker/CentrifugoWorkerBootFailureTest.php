<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

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
