<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

class WorkerRegistry
{
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
