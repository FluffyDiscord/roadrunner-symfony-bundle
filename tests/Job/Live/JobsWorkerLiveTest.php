<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Live;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live end-to-end test for the RoadRunner Jobs (queue consumer) worker.
 *
 * Asserts the worker's deterministic guarantee against a real jobs pool: a successful task is
 * consumed and acked EXACTLY ONCE (the handler runs a single time and the task is not redelivered).
 *
 * The nack-with-requeue path is intentionally NOT asserted here: `redelivery` is driver-dependent —
 * the memory pipeline used by the harness may legitimately drop rather than redeliver — so requeue
 * is proven deterministically by the unit tests (via the SpyReceivedTask double), not against a live
 * driver whose behavior may vary.
 */
#[Group('jobs-live')]
class JobsWorkerLiveTest extends AbstractJobsLiveTestCase
{
    public function testSuccessfulTaskIsConsumedAndAckedExactlyOnce(): void
    {
        $token = 'ack-' . getmypid() . '-' . uniqid();
        $counter = self::varDir() . '/count-' . $token . '.txt';
        @unlink($counter);

        self::assertSame(200, self::httpGet('/dispatch-count?token=' . $token), 'dispatch endpoint did not return 200');

        $deadline = microtime(true) + 15.0;
        while (!is_file($counter) && microtime(true) < $deadline) {
            usleep(200_000);
        }
        self::assertFileExists($counter, 'the jobs worker never consumed the task');

        // Settle: a missing or duplicated ack would surface as a redelivery, bumping the counter past 1.
        sleep(3);
        self::assertSame(
            '1',
            trim((string) file_get_contents($counter)),
            'the task was delivered more than once — ack-on-success / ack-exactly-once was violated',
        );
    }

    public function testTaskAfterAnErrorRunsOnceOnAFreshWorker(): void
    {
        $crashPidFile = self::varDir() . '/crash-pid.txt';
        $token = 'after-crash-' . getmypid() . '-' . uniqid();
        $counter = self::varDir() . '/count-' . $token . '.txt';
        $pidFile = self::varDir() . '/pid-' . $token . '.txt';

        self::assertSame(200, self::httpGet('/dispatch-crash'), 'crash dispatch endpoint did not return 200');
        self::assertTrue(self::waitForFile($crashPidFile), 'the crashing task never ran');

        self::assertSame(200, self::httpGet('/dispatch-count?token=' . $token), 'dispatch endpoint did not return 200');
        self::assertTrue(self::waitForFile($counter), 'the task after the crash was never consumed');

        sleep(3);
        self::assertSame('1', trim((string) file_get_contents($counter)), 'the task after the crash ran more than once');
        self::assertNotSame(
            trim((string) file_get_contents($crashPidFile)),
            trim((string) file_get_contents($pidFile)),
            'the task after the crash ran on the worker that threw \Error',
        );

        $redeliveryRuns = file(self::varDir() . '/crash-redelivered.txt', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertLessThanOrEqual(1, count($redeliveryRuns), 'the redelivered crash task ran on the old and the fresh worker: ' . implode(',', $redeliveryRuns));
    }

    private static function waitForFile(string $path): bool
    {
        $deadline = microtime(true) + 15.0;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(200_000);
        }

        return is_file($path);
    }
}
