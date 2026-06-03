<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Attribute;

use Temporal\Worker\WorkerFactoryInterface;

/**
 * Assign a workflow or activity to a Temporal task queue. The bundle auto-registers a
 * worker for every declared queue, so naming a queue here is all that is needed — repeat
 * the attribute to serve a class from more than one queue. Place it on the class or its
 * interface.
 *
 * https://docs.temporal.io/best-practices/worker#separate-task-queues-logically
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class TaskQueue
{
    public function __construct(
        public readonly string $taskQueue = WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
    )
    {
    }
}
