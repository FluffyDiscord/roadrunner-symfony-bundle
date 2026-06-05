<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\WorkerOptions;

interface TemporalWorkerInterface
{
    public function getTaskQueue(): string;

    public function getWorkerOptions(): WorkerOptions;
}
