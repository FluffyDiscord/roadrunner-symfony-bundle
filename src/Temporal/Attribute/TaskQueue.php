<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Attribute;

use Temporal\Worker\WorkerFactoryInterface;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class TaskQueue
{
    public function __construct(
        public readonly string $taskQueue = WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
    )
    {
    }
}
