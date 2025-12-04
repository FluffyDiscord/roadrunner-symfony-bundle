<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Attribute;

use Temporal\Worker\WorkerFactoryInterface;

/**
 * Use this to assign workflows and activities to their respective workers.
 * https://docs.temporal.io/best-practices/worker#separate-task-queues-logically
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class AssignToWorker
{
    public function __construct(
        public readonly string $taskQueue = WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
    )
    {
    }
}