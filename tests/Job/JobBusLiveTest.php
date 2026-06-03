<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live end-to-end test for the Jobs message bus (IT-LIVE in docs/specs/jobs-message-bus.md §N-2).
 *
 * SKIPPED by default. Requires a provisioned RoadRunner environment:
 *
 *   1. The `rr` server binary on PATH and `RR_RPC` set (e.g. tcp://127.0.0.1:6001).
 *   2. A `.rr.yaml` with an `rpc:` section and a jobs pool, e.g.:
 *
 *        version: "3"
 *        rpc: { listen: "tcp://127.0.0.1:6001" }
 *        server: { command: "php worker.php" }    # boots this bundle's runtime in MODE_JOBS
 *        jobs:
 *          pool: { num_workers: 1 }
 *          pipelines: { default: { driver: memory } }
 *          consume: [ "default" ]
 *
 *   3. A consumer command running this bundle in MODE_JOBS with at least one #[AsJobHandler].
 *
 * Then: JobDispatcher::dispatch($message) pushes an enveloped task; the worker consumes it, the
 * JobRoutingListener rehydrates it and invokes the handler, and the task is acked.
 *
 * Enable with RR_JOBS_LIVE=1.
 *
 * @group jobs-live
 */
#[Group('jobs-live')]
class JobBusLiveTest extends BaseTestCase
{
    public function testDispatchedMessageIsRoutedToHandler(): void
    {
        if (getenv('RR_JOBS_LIVE') !== '1') {
            $this->markTestSkipped(
                'Live Jobs message-bus test. Set RR_JOBS_LIVE=1 with a provisioned rr binary + jobs pool + a '
                . '#[AsJobHandler] to run it. See the class docblock and docs/specs/jobs-message-bus.md §N-2 IT-LIVE.',
            );
        }

        $this->markTestSkipped('RR_JOBS_LIVE=1 set, but no automated live harness is provisioned in this repo. See docblock.');
    }
}
