<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Psr\Log\LoggerInterface;

/** See docs/specs/worker-warmup.md ADR-2, ADR-5. */
readonly class WorkerWarmupRunner
{
    /**
     * Under "http.pool.debug: true" RoadRunner spawns a fresh process for every request, so
     * nothing warmed survives to a second request and the boot-time replay only adds latency.
     * Worse, compiling cached Twig templates early-binds their classes, which makes Twig skip
     * its freshness check and serve stale templates in development. The runner therefore
     * no-ops unless the kernel runs in persistent worker mode (kernel.runtime_mode.worker).
     *
     * @param iterable<WorkerWarmerInterface> $warmers priority-ordered tagged iterator
     */
    public function __construct(
        private iterable         $warmers,
        private ?LoggerInterface $logger = null,
        private bool             $persistentWorker = true,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        if (!$this->persistentWorker) {
            $this->logger?->debug('RoadRunner warmup: skipped, the worker pool runs in debug mode (one process per request).');

            return;
        }

        $totalStart = microtime(true);
        $warmerCount = 0;

        // stdout carries the goridge worker protocol; any stray output (echo, PHP warning
        // with stdout display_errors) during warmup would corrupt it and kill the worker.
        HttpWorker::$bootWarmupInProgress = true;
        ob_start();

        try {
            // The foreach itself can throw: tagged-iterator advancement lazily
            // instantiates each warmer service, and a failing constructor anywhere in a
            // warmer's dependency graph surfaces at the loop statement, not inside
            // warmup(). An escape here would kill the worker before the serve loop and
            // crash-loop the pool — the exact failure class this feature removed.
            try {
                foreach ($this->warmers as $warmer) {
                    $warmerStart = microtime(true);

                    try {
                        $warmer->warmup();
                    } catch (\Throwable $throwable) {
                        $this->logger?->error('RoadRunner warmup: warmer "{warmer}" failed; continuing.', [
                            'warmer' => $warmer::class,
                            'exception' => $throwable,
                        ]);
                    }

                    $warmerCount++;

                    $this->logger?->debug('RoadRunner warmup: "{warmer}" took {duration_ms}ms.', [
                        'warmer' => $warmer::class,
                        'duration_ms' => round((microtime(true) - $warmerStart) * 1000, 1),
                    ]);
                }
            } catch (\Throwable $throwable) {
                $this->logger?->error('RoadRunner warmup: aborted while instantiating a warmer; remaining warmers skipped.', [
                    'exception' => $throwable,
                ]);
            }
        } finally {
            $capturedOutput = ob_get_clean();
            HttpWorker::$bootWarmupInProgress = false;

            if ($capturedOutput !== false && $capturedOutput !== '') {
                $this->logger?->warning('RoadRunner warmup: discarded {bytes} bytes of stray output (stdout is the worker protocol).', [
                    'bytes' => strlen($capturedOutput),
                ]);
            }
        }

        $this->logger?->info('RoadRunner warmup: {count} warmers finished in {duration_ms}ms.', [
            'count' => $warmerCount,
            'duration_ms' => round((microtime(true) - $totalStart) * 1000, 1),
        ]);
    }
}
