<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Environment\Mode;

/**
 * Learns what real traffic loads. Each record captures the FULL current declared-symbol
 * and cache-dir-include sets: replay is idempotent and the storage union-merge dedupes,
 * so a kernel reboot mid-learning (fresh instance) is harmless. A baseline-delta design
 * must not be reintroduced — any baseline captured at or after the first response
 * swallows that request's cold-load graph, the single biggest thing to learn.
 * A write is skipped when neither set grew since the last record.
 * See docs/specs/worker-warmup.md ADR-7.
 */
class WarmupManifestRecorder
{
    private int $respondedRequests = 0;

    private int $lastSymbolCount = 0;

    private int $lastFileCount = 0;

    private bool $done = false;

    public function __construct(
        private readonly WarmupManifestStorage $storage,
        private readonly string                $cacheDir,
        private readonly int                   $learnRequests,
        private readonly ?LoggerInterface      $logger = null,
        private readonly bool                  $persistentWorker = true,
    )
    {
    }

    public function __invoke(WorkerResponseSentEvent $event): void
    {
        if ($this->done || !$this->persistentWorker) {
            return;
        }

        if ($event->workerType !== Mode::MODE_HTTP) {
            return;
        }

        try {
            $this->record();
        } catch (\Throwable $throwable) {
            $this->logger?->error('RoadRunner warmup: recording failed for this response; will retry on the next one.', [
                'exception' => $throwable,
            ]);
        }

        $this->respondedRequests++;
        if ($this->respondedRequests >= $this->learnRequests) {
            $this->done = true;
        }
    }

    private function record(): void
    {
        $currentSymbols = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
        $currentFiles = get_included_files();

        if (count($currentSymbols) === $this->lastSymbolCount && count($currentFiles) === $this->lastFileCount) {
            return;
        }

        $cacheFiles = array_values(array_filter(
            $currentFiles,
            fn(string $file) => str_starts_with($file, $this->cacheDir),
        ));

        ob_start();
        try {
            $written = $this->storage->write($currentSymbols, $cacheFiles);
        } finally {
            ob_end_clean();
        }

        if ($written) {
            $this->lastSymbolCount = count($currentSymbols);
            $this->lastFileCount = count($currentFiles);
        }
    }
}
