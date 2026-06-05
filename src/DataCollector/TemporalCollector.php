<?php

namespace FluffyDiscord\RoadRunnerBundle\DataCollector;

use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use Spiral\Attributes\AttributeReader;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Temporal\Internal\Declaration\Reader\WorkflowReader;

/**
 * @phpstan-type CollectorEntry array{class: string, ids: list<string>, taskQueues: list<string>}
 * @phpstan-type CollectorTypeRow array{class: string, id: string}
 * @phpstan-type CollectorWorker array{class: string, id: string, workflows: list<CollectorTypeRow>, activities: list<CollectorTypeRow>}
 */
final class TemporalCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TemporalWorkerInitializer $temporalWorkerInitializer,
    )
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $workflowsByQueue = $this->temporalWorkerInitializer->getRegisteredWorkflows();
        $activitiesByQueue = $this->temporalWorkerInitializer->getRegisteredActivities();

        $reader = new AttributeReader();
        $workflowReader = new WorkflowReader($reader);
        $activityReader = new ActivityReader($reader);

        /** @var array<class-string, list<string>> $workflowIds */
        $workflowIds = [];
        foreach ($workflowsByQueue as $classes) {
            foreach ($classes as $class) {
                $workflowIds[$class] ??= $this->workflowIds($workflowReader, $class);
            }
        }

        /** @var array<class-string, list<string>> $activityIds */
        $activityIds = [];
        foreach ($activitiesByQueue as $classes) {
            foreach ($classes as $class) {
                $activityIds[$class] ??= $this->activityIds($activityReader, $class);
            }
        }

        /** @var list<CollectorWorker> $workers */
        $workers = [];
        foreach ($this->temporalWorkerInitializer->getWorkerSummaries() as $summary) {
            $taskQueue = $summary['taskQueue'];
            $workers[] = [
                'class'      => $summary['class'],
                'id'         => $taskQueue,
                'workflows'  => $this->typeRows($workflowsByQueue[$taskQueue] ?? [], $workflowIds),
                'activities' => $this->typeRows($activitiesByQueue[$taskQueue] ?? [], $activityIds),
            ];
        }

        $this->data = [
            'workers'    => $workers,
            'workflows'  => $this->byClass($workflowsByQueue, $workflowIds),
            'activities' => $this->byClass($activitiesByQueue, $activityIds),
        ];
    }

    /**
     * @param list<class-string>                $classes
     * @param array<class-string, list<string>> $idsByClass
     * @return list<CollectorTypeRow>
     */
    private function typeRows(array $classes, array $idsByClass): array
    {
        $rows = [];
        foreach ($classes as $class) {
            $ids = $idsByClass[$class] ?? [];
            if ($ids === []) {
                $rows[] = ['class' => $class, 'id' => ''];
                continue;
            }
            foreach ($ids as $id) {
                $rows[] = ['class' => $class, 'id' => $id];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, list<class-string>> $byQueue
     * @param array<class-string, list<string>> $idsByClass
     * @return array<class-string, CollectorEntry>
     */
    private function byClass(array $byQueue, array $idsByClass): array
    {
        $result = [];
        foreach ($byQueue as $taskQueue => $classes) {
            foreach ($classes as $class) {
                $previous = $result[$class]['taskQueues'] ?? [];
                $result[$class] = [
                    'class'      => $class,
                    'ids'        => $idsByClass[$class] ?? [],
                    'taskQueues' => array_values(array_unique([...$previous, $taskQueue])),
                ];
            }
        }

        return $result;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function workflowIds(WorkflowReader $reader, string $class): array
    {
        try {
            return [$reader->fromClass($class)->getID()];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function activityIds(ActivityReader $reader, string $class): array
    {
        try {
            return array_values(array_map(
                static fn ($prototype) => $prototype->getID(),
                $reader->fromClass($class),
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getWorkers(): array
    {
        $workers = is_array($this->data) ? ($this->data['workers'] ?? null) : null;

        return is_array($workers) ? $workers : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getActivities(): array
    {
        $activities = is_array($this->data) ? ($this->data['activities'] ?? null) : null;

        return is_array($activities) ? $activities : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getWorkflows(): array
    {
        $workflows = is_array($this->data) ? ($this->data['workflows'] ?? null) : null;

        return is_array($workflows) ? $workflows : [];
    }

    public function getName(): string
    {
        return 'fluffy_discord.roadrunner.temporal';
    }
}
