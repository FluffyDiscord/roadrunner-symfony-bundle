<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Attribute;

use Temporal\Worker\WorkerFactoryInterface;

/**
 * Use this to assign workflows and activities to their respective workers.
 * https://docs.temporal.io/best-practices/worker#separate-task-queues-logically
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class TemporalTaskQueue
{
    public readonly array $name;

    public function __construct(
        array|string $name = [WorkerFactoryInterface::DEFAULT_TASK_QUEUE],
    )
    {
        if (!is_array($name)) {
            $name = [$name];
        }

        $this->name = $name;
    }
}