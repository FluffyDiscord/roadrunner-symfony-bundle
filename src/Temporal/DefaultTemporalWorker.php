<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerOptions;

class DefaultTemporalWorker implements TemporalWorkerInterface
{
    /**
     * @param array<string, mixed> $workerOptions
     */
    public function __construct(
        private readonly string $taskQueue = WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
        private readonly array  $workerOptions = [],
    )
    {
    }

    public function getTaskQueue(): string
    {
        return $this->taskQueue;
    }

    public function getWorkerOptions(): WorkerOptions
    {
        $options = WorkerOptions::new();
        foreach ($this->workerOptions as $key => $value) {
            $options->{$key} = $value;
        }

        return $options;
    }
}
