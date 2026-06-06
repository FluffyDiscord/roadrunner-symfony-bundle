<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Internal\Support\DateInterval;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerOptions;

class DefaultTemporalWorker implements TemporalWorkerInterface
{
    /**
     * @param array<string, mixed> $workerOptions
     */
    public function __construct(
        private readonly string $taskQueue = WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
        private readonly array  $workerOptions = [],
    )
    {
    }

    public function getTaskQueue(): string
    {
        return $this->taskQueue;
    }

    public function getWorkerOptions(): WorkerOptions
    {
        $options = WorkerOptions::new();
        foreach ($this->workerOptions as $key => $value) {
            // A \DateInterval-typed option carries an int (seconds) or duration string in config; parse
            // it into the \DateInterval the property requires — a raw int/string write TypeErrors at boot.
            // The config validator (Configuration::workerOptionsValidator) has already rejected any
            // option that is neither scalar nor \DateInterval, so the else-branch is always a safe write.
            $type = (new \ReflectionProperty(WorkerOptions::class, $key))->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'DateInterval') {
                $options->{$key} = DateInterval::parse($value, DateInterval::FORMAT_SECONDS);
            } else {
                $options->{$key} = $value;
            }
        }

        return $options;
    }
}
