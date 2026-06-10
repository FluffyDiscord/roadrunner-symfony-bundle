<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Debug;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\ActivityStubReader;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Temporal\Internal\Declaration\Reader\WorkflowReader;

/**
 * Compile-time view of the registered Temporal workflows/activities and their declared stubs.
 * Reads the registration map and reflection only — it never opens a Temporal connection.
 *
 * @phpstan-import-type StubRow from TemporalIntrospectorInterface
 */
final class TemporalIntrospector implements TemporalIntrospectorInterface
{
    private ?WorkflowReader $workflowReader = null;
    private ?ActivityReader $activityReader = null;

    public function __construct(
        private readonly TemporalWorkerInitializer $initializer,
    )
    {
    }

    /** @return array<string, list<class-string>> queue => workflow classes */
    public function workflowsByQueue(): array
    {
        return $this->initializer->getRegisteredWorkflows();
    }

    /** @return array<string, list<class-string>> queue => activity classes */
    public function activitiesByQueue(): array
    {
        return $this->initializer->getRegisteredActivities();
    }

    /** @return list<string> all registered task-queue names, sorted */
    public function queues(): array
    {
        $queues = array_keys($this->workflowsByQueue() + $this->activitiesByQueue());
        sort($queues);

        return $queues;
    }

    /** @return list<string> the queues a workflow class is registered on */
    public function queuesOfWorkflow(string $class): array
    {
        $queues = [];
        foreach ($this->workflowsByQueue() as $queue => $classes) {
            if (in_array($class, $classes, true)) {
                $queues[] = $queue;
            }
        }

        return $queues;
    }

    /**
     * @param class-string $workflowClass
     * @return list<StubRow>
     */
    public function stubs(string $workflowClass): array
    {
        $ownQueue = $this->queuesOfWorkflow($workflowClass)[0] ?? '?';

        $rows = [];
        foreach (ActivityStubReader::pairs(new \ReflectionClass($workflowClass)) as [$property, $stub]) {
            $rows[] = [
                'property'        => $property->getName(),
                'typed'           => $property->hasType(),
                'activity'        => $stub->activity,
                'activityShort'   => self::shortName($stub->activity),
                'resolvedQueue'   => $stub->queue ?? ($ownQueue . ' (inherited)'),
                'startToClose'    => self::duration($stub->startToClose),
                'hasCloseTimeout' => $stub->startToClose !== null || $stub->scheduleToClose !== null,
                'retry'           => self::retrySummary($stub),
                'stub'            => $stub,
            ];
        }

        return $rows;
    }

    /** @param class-string $class */
    public function workflowId(string $class): ?string
    {
        try {
            return ($this->workflowReader ??= new WorkflowReader(new AttributeReader()))->fromClass($class)->getID();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    public function activityIds(string $class): array
    {
        try {
            $reader = $this->activityReader ??= new ActivityReader(new AttributeReader());

            return array_values(array_map(static fn ($prototype): string => $prototype->getID(), $reader->fromClass($class)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{class: class-string, taskQueue: string}> */
    public function workerSummaries(): array
    {
        return $this->initializer->getWorkerSummaries();
    }

    public static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    public static function duration(int|string|\DateInterval|null $value): string
    {
        return match (true) {
            $value === null            => 'no-s2c',
            is_int($value)             => $value . 's',
            is_string($value)         => $value,
            $value instanceof \DateInterval => self::intervalToSeconds($value) . 's',
        };
    }

    private static function intervalToSeconds(\DateInterval $interval): int
    {
        $base = new \DateTimeImmutable('@0');

        return $base->add($interval)->getTimestamp();
    }

    public static function retrySummary(ActivityStub $stub): string
    {
        if ($stub->retryAttempts !== null) {
            return $stub->retryAttempts === 0 ? 'unlimited' : (string) $stub->retryAttempts;
        }

        if ($stub->retryBackoff !== null || $stub->retryInitialInterval !== null || $stub->retryMaxInterval !== null || $stub->nonRetryable !== []) {
            return 'custom';
        }

        return 'default';
    }
}
