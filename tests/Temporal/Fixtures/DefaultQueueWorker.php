<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use Temporal\Worker\WorkerOptions;

class DefaultQueueWorker implements TemporalWorkerInterface
{
    public function getTaskQueue(): string
    {
        return 'default';
    }

    public function getWorkerOptions(): WorkerOptions
    {
        return WorkerOptions::new();
    }
}
