<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\WorkerInterface;

final class TemporalWorkerRegistry
{
    /** @var array<string, WorkerInterface> */
    private array $workers = [];

    public function add(string $taskQueue, WorkerInterface $worker): void
    {
        $this->workers[$taskQueue] = $worker;
    }

    public function get(string $taskQueue): ?WorkerInterface
    {
        return $this->workers[$taskQueue] ?? null;
    }

    public function has(string $taskQueue): bool
    {
        return isset($this->workers[$taskQueue]);
    }

    /**
     * @return array<string, WorkerInterface>
     */
    public function all(): array
    {
        return $this->workers;
    }
}
