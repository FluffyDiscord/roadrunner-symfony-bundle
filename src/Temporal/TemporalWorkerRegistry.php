<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\WorkerInterface;

/**
 * Holds the SDK worker instances created at boot, keyed by task queue, so they can be
 * retrieved at runtime — e.g. from an activity, an interceptor or an event listener that
 * needs to inspect a worker (its {@see WorkerInterface::getOptions()}, registered
 * workflows/activities, etc.).
 *
 * Workers cannot be pre-registered as DI services: each is built from the live
 * {@see TemporalWorkerFactoryInterface} at boot, not at compile time. This registry is
 * populated by {@see \FluffyDiscord\RoadRunnerBundle\Worker\TemporalWorker::start()} once
 * the Temporal worker boots — so it is only filled **inside the running Temporal worker
 * process**. In a web/HTTP or any other process no workers are instantiated and it stays
 * empty; check {@see has()} / {@see all()} before relying on a worker being present.
 */
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
