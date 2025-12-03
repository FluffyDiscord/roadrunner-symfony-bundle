<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TemporalTaskQueue;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;

/**
 * Use this to create new worker task queue with its own workflows and activities.
 * For simplicity’s sake, you just need one task queue at the start
 * which this bundle already provides.
 *
 * Do not directly add activities and workflows here,
 * use {@see TemporalTaskQueue} for assigning them to the worker task queue.
 *
 * https://docs.temporal.io/best-practices/worker#separate-task-queues-logically
 */
interface TemporalWorkerInterface
{
    public function create(WorkerFactoryInterface $workerFactory): WorkerInterface;
}