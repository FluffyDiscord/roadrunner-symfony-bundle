<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\CounterWorkflowInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\FailingWorkflowInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\GreetingWorkflowInterface;
use PHPUnit\Framework\Attributes\Group;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowFailedException;

/**
 * Live Temporal integration tests (IT-01..IT-05), exercised against a REAL Temporal server +
 * RoadRunner `temporal` plugin. SKIPPED by default so the standard `php vendor/bin/phpunit tests`
 * run stays green without that infrastructure; they execute only when TEMPORAL_LIVE=1 and a worker
 * is reachable.
 *
 * The authoritative way to run them is the Docker harness tests/docker-validate-temporal.sh, which
 * provisions `temporal server start-dev` + a real RoadRunner temporal worker (registering the
 * worker-side implementations of this namespace's Workflow\* contracts) and then invokes
 * `phpunit --group temporal-live` inside the container — so these assertions ARE the live check.
 *
 * Required environment (see docs/specs/temporal-io-integration.md §7):
 *   1. A reachable Temporal server on 127.0.0.1:7233 (TEMPORAL_ADDRESS overrides the host:port).
 *   2. A RoadRunner `temporal` worker polling the "default" task queue with this namespace's
 *      Workflow\* contracts implemented + assigned via #[TaskQueue('default')].
 *   3. TEMPORAL_LIVE=1. TEMPORAL_INTERCEPTOR_MARKER points at the file the worker's
 *      ActivityInbound listener appends to (IT-03).
 */
#[Group('temporal-live')]
class TemporalLiveTest extends BaseTestCase
{
    private const TASK_QUEUE = 'default';

    private static ?WorkflowClientInterface $client = null;

    public static function setUpBeforeClass(): void
    {
        if (self::liveEnabled() && class_exists(WorkflowClient::class)) {
            self::$client = WorkflowClient::create(ServiceClient::create(self::address()));
            self::waitForWorker();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::liveEnabled()) {
            $this->markTestSkipped('Live Temporal tests require TEMPORAL_LIVE=1 and a provisioned Temporal server + RoadRunner temporal worker. See docs/specs/temporal-io-integration.md §7.');
        }

        if (!class_exists(WorkflowClient::class)) {
            $this->markTestSkipped('temporal/sdk is not installed.');
        }
    }

    /**
     * IT-01 / IT-02 — A GreetingWorkflow that calls GreetingActivity returns the activity output.
     */
    public function testWorkflowExecution(): void
    {
        self::assertSame('Hello, World', $this->greet('World'));
    }

    /**
     * IT-03 — During a real run, the bundle's ActivityInbound interceptor event fires inside the
     * worker; its listener appends to the marker file, which this client can observe (shared FS).
     */
    public function testInterceptorEventFiresDuringRealRun(): void
    {
        $marker = self::marker();
        if ($marker === null) {
            self::markTestSkipped('TEMPORAL_INTERCEPTOR_MARKER not set; cannot observe the worker-side interceptor.');
        }

        $this->greet('Interceptor');

        $fired = false;
        for ($i = 0; $i < 50; ++$i) {
            if (is_file($marker) && filesize($marker) > 0) {
                $fired = true;
                break;
            }
            usleep(200_000);
        }

        self::assertTrue($fired, "ActivityInbound interceptor never wrote the marker at {$marker}");
    }

    /**
     * IT-04 — Signals mutate workflow state observable through a query; a final signal completes it.
     */
    public function testSignalAndQuery(): void
    {
        $workflow = self::$client->newWorkflowStub(
            CounterWorkflowInterface::class,
            WorkflowOptions::new()
                ->withTaskQueue(self::TASK_QUEUE)
                ->withWorkflowExecutionTimeout(60),
        );

        $run = self::$client->start($workflow);

        $workflow->add(5);
        $workflow->add(7);

        $count = -1;
        for ($i = 0; $i < 50; ++$i) {
            $count = $workflow->getCount();
            if ($count === 12) {
                break;
            }
            usleep(200_000);
        }

        self::assertSame(12, $count, 'query did not observe both signals');

        $workflow->finish();
        self::assertSame(12, $run->getResult('int'));
    }

    /**
     * IT-05 (breaking) — An activity that always throws surfaces as a client-side exception
     * end-to-end, rather than returning a value or hanging past the execution timeout.
     */
    public function testActivityFailurePropagatesToClient(): void
    {
        $workflow = self::$client->newWorkflowStub(
            FailingWorkflowInterface::class,
            WorkflowOptions::new()
                ->withTaskQueue(self::TASK_QUEUE)
                ->withWorkflowExecutionTimeout(30),
        );

        $this->expectException(WorkflowFailedException::class);

        $workflow->run();
    }

    private function greet(string $name): string
    {
        $workflow = self::$client->newWorkflowStub(
            GreetingWorkflowInterface::class,
            WorkflowOptions::new()
                ->withTaskQueue(self::TASK_QUEUE)
                ->withWorkflowExecutionTimeout(30),
        );

        return $workflow->greet($name);
    }

    /**
     * Polls a real workflow until the worker is registered and polling the queue, so the assertions
     * below do not race a not-yet-ready worker.
     */
    private static function waitForWorker(): void
    {
        $client = self::$client;
        if (!$client instanceof WorkflowClientInterface) {
            return;
        }

        for ($i = 0; $i < 60; ++$i) {
            try {
                $workflow = $client->newWorkflowStub(
                    GreetingWorkflowInterface::class,
                    WorkflowOptions::new()
                        ->withTaskQueue(self::TASK_QUEUE)
                        ->withWorkflowExecutionTimeout(15),
                );

                if ($workflow->greet('Ready') === 'Hello, Ready') {
                    return;
                }
            } catch (\Throwable) {
                // worker not polling yet — retry
            }

            sleep(1);
        }
    }

    private static function liveEnabled(): bool
    {
        return ($_SERVER['TEMPORAL_LIVE'] ?? $_ENV['TEMPORAL_LIVE'] ?? getenv('TEMPORAL_LIVE')) === '1';
    }

    private static function address(): string
    {
        $address = $_SERVER['TEMPORAL_ADDRESS'] ?? $_ENV['TEMPORAL_ADDRESS'] ?? getenv('TEMPORAL_ADDRESS');

        return is_string($address) && $address !== '' ? $address : '127.0.0.1:7233';
    }

    private static function marker(): ?string
    {
        $marker = $_SERVER['TEMPORAL_INTERCEPTOR_MARKER'] ?? $_ENV['TEMPORAL_INTERCEPTOR_MARKER'] ?? getenv('TEMPORAL_INTERCEPTOR_MARKER');

        return is_string($marker) && $marker !== '' ? $marker : null;
    }
}
