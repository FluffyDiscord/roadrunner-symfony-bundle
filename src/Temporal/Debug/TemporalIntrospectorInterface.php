<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Debug;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;

/**
 * Read-only view of the registered Temporal workflows/activities and their declared stubs.
 *
 * @phpstan-type StubRow array{property: string, typed: bool, activity: string, activityShort: string, resolvedQueue: string, startToClose: string, hasCloseTimeout: bool, retry: string, stub: ActivityStub}
 */
interface TemporalIntrospectorInterface
{
    /** @return array<string, list<class-string>> queue => workflow classes */
    public function workflowsByQueue(): array;

    /** @return array<string, list<class-string>> queue => activity classes */
    public function activitiesByQueue(): array;

    /** @return list<string> all registered task-queue names */
    public function queues(): array;

    /**
     * @param class-string $class
     * @return list<string>
     */
    public function queuesOfWorkflow(string $class): array;

    /**
     * @param class-string $workflowClass
     * @return list<StubRow>
     */
    public function stubs(string $workflowClass): array;

    /** @param class-string $class */
    public function workflowId(string $class): ?string;

    /**
     * @param class-string $class
     * @return list<string>
     */
    public function activityIds(string $class): array;

    /** @return list<array{class: class-string, taskQueue: string}> */
    public function workerSummaries(): array;
}
