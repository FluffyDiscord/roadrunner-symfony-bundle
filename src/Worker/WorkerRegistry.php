<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

class WorkerRegistry
{
    /** @var array<string, WorkerInterface> */
    private array $workers = [];

    public function registerWorker(string $mode, WorkerInterface $worker): void
    {
        $this->workers[$mode] = $worker;
    }

    public function getWorker(string $mode): ?WorkerInterface
    {
        return $this->workers[$mode] ?? null;
    }
}
