<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live RoadRunner Jobs integration test (IT-LIVE in docs/specs/rr-jobs-worker.md §N-2).
 *
 * This test is SKIPPED by default. It requires a provisioned RoadRunner environment:
 *
 *   1. The `rr` server binary on PATH.
 *   2. A `.rr.yaml` declaring a jobs pool, e.g.:
 *
 *        version: "3"
 *        rpc: { listen: "tcp://127.0.0.1:6001" }
 *        server:
 *          command: "php worker.php"        # boots this bundle's runtime in MODE_JOBS
 *        jobs:
 *          pool: { num_workers: 1 }
 *          pipelines:
 *            test: { driver: memory, config: { priority: 10 } }
 *          consume: [ "test" ]
 *
 *   3. A producer to push a task onto the `test` pipeline (spiral/roadrunner-jobs `Jobs`/`Queue`
 *      API over the RPC port, or `rr jobs`), plus a consumer command running this bundle.
 *
 * Enable by setting RR_JOBS_LIVE=1 (and ensuring the binary + harness exist). Without a real RR
 * server there is nothing to assert against, so the test short-circuits with markTestSkipped()
 * rather than failing — mirroring the Centrifugo "live surface is out of scope" decision.
 *
 * @group jobs-live
 */
#[Group('jobs-live')]
class JobsWorkerLiveTest extends BaseTestCase
{
    public function testLiveJobsPipelineAcksAndRequeues(): void
    {
        if (getenv('RR_JOBS_LIVE') !== '1') {
            $this->markTestSkipped(
                'Live RoadRunner Jobs test. Set RR_JOBS_LIVE=1 with a provisioned rr binary + jobs pool to run it. '
                . 'See the class docblock for the required .rr.yaml and producer setup.',
            );
        }

        // Intentionally not implemented as an automated assertion here: driving a real rr server +
        // jobs pool + producer is an environment-provisioning task (documented in the docblock and
        // docs/specs/rr-jobs-worker.md §N-2 IT-LIVE), not a unit-test fixture. When RR_JOBS_LIVE=1
        // this is the entry point an operator wires their provisioned harness into.
        $this->markTestSkipped('RR_JOBS_LIVE=1 set, but no automated live harness is provisioned in this repo. See docblock.');
    }
}
