<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TemporalTaskQueue;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;

/**
 * Use this to create new worker queue with its workflows and activities.
 * For simplicity’s sake, you just need one task queue at the start.
 *
 * Do not directly add activities and workflows,
 * use {@see TemporalTaskQueue} for assigning them to the worker task queue.
 *
 * https://docs.temporal.io/best-practices/worker#separate-task-queues-logically
 */
interface TemporalWorkerInterface
{
    public function create(WorkerFactoryInterface $workerFactory): WorkerInterface;
}