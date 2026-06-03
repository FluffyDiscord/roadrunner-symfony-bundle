<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use Temporal\Worker\WorkerOptions;

/**
 * Describes a Temporal worker the bundle should run: a task queue and its
 * {@see WorkerOptions}. The bundle creates the SDK worker (via the shared
 * {@see TemporalWorkerFactoryInterface}) and registers the workflows/activities
 * assigned to the queue — you only describe it here.
 *
 * You rarely implement this directly: the bundle ships {@see DefaultTemporalWorker}
 * for the default queue and auto-registers one for every other queue named in
 * {@see TaskQueue} (configurable via `temporal.worker_options.<queue>`). Implement it
 * only to define a queue's options in code rather than configuration.
 *
 * https://docs.temporal.io/best-practices/worker#separate-task-queues-logically
 */
interface TemporalWorkerInterface
{
    public function getTaskQueue(): string;

    public function getWorkerOptions(): WorkerOptions;
}
