<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\WorkerFactoryInterface;

interface TemporalWorkerFactoryInterface
{
    public function create(): WorkerFactoryInterface;
}