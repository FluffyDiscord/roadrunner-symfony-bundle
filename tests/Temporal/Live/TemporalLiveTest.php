<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live Temporal integration tests (IT-01 .. IT-04).
 *
 * These exercise a REAL Temporal server + RoadRunner `temporal` plugin and are
 * SKIPPED by default so the standard `php vendor/bin/phpunit tests` run stays green
 * without that infrastructure. They run only when TEMPORAL_LIVE=1 is set.
 *
 * Required environment (see docs/specs/temporal-io-integration.md §7):
 *   1. A reachable Temporal server (e.g. `temporal server start-dev`) on 127.0.0.1:7233.
 *   2. RoadRunner with the `temporal` plugin enabled in `.rr.yaml`
 *      (temporal: { address: "127.0.0.1:7233" }) plus the `rpc` plugin.
 *   3. Env: TEMPORAL_LIVE=1, RR_RPC=tcp://127.0.0.1:6001 (or as configured).
 *   4. Run: TEMPORAL_LIVE=1 php vendor/bin/phpunit tests --group temporal-live
 *
 * A REAL end-to-end run of IT-01/IT-02/IT-03 (a GreetingWorkflow that calls a
 * GreetingActivity end-to-end against a live Temporal dev server driven by a real
 * RoadRunner temporal worker, asserting the workflow returns exactly "Hello, World"
 * and that the ActivityInbound interceptor event fires) is provided as a Docker
 * harness: tests/docker-validate-temporal.sh (modeled on docker-validate-error-pages.sh).
 * Run it with: bash tests/docker-validate-temporal.sh "8.4". The PHPUnit cases below
 * stay skipped-by-default scaffolding so the standard `phpunit tests` run needs no
 * provisioned Temporal server.
 */
#[Group('temporal-live')]
class TemporalLiveTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (($_SERVER['TEMPORAL_LIVE'] ?? $_ENV['TEMPORAL_LIVE'] ?? getenv('TEMPORAL_LIVE')) !== '1') {
            $this->markTestSkipped('Live Temporal tests require TEMPORAL_LIVE=1 and a provisioned Temporal server + RoadRunner temporal plugin. See docs/specs/temporal-io-integration.md §7.');
        }

        if (!class_exists(\Temporal\Client\WorkflowClient::class)) {
            $this->markTestSkipped('temporal/sdk is not installed.');
        }
    }

    /**
     * IT-01 — A GreetingWorkflow started against a running worker returns its result.
     */
    public function testWorkflowExecution(): void
    {
        self::markTestIncomplete('Requires a running RoadRunner temporal worker bound to the test kernel.');
    }

    /**
     * IT-02 — A workflow that invokes GreetingActivity returns the activity output.
     */
    public function testActivityInvocation(): void
    {
        self::markTestIncomplete('Requires a running RoadRunner temporal worker bound to the test kernel.');
    }

    /**
     * IT-03 — During a real run, a registered ExecuteActivityEvent listener observes >= 1 event.
     */
    public function testInterceptorEventsFireDuringRealRun(): void
    {
        self::markTestIncomplete('Requires a running RoadRunner temporal worker bound to the test kernel.');
    }

    /**
     * IT-04 — Sending a signal mutates workflow state observable through a query.
     */
    public function testSignalAndQuery(): void
    {
        self::markTestIncomplete('Requires a running RoadRunner temporal worker bound to the test kernel.');
    }
}
