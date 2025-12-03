<?php

namespace FluffyDiscord\RoadRunnerBundle\DataCollector;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TemporalTaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TemporalCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TemporalWorkerInitializer $workerInitializer,
    )
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'activities'        => $this->workerInitializer->activities,
            'workflows'         => $this->workerInitializer->workflows,
            'workers'           => array_keys($this->workerInitializer->workers),
            'worker_activities' => [],
            'worker_workflows'  => [],
        ];

        foreach ($this->data['workflows'] as $workflow) {
            $reflection = new \ReflectionClass($workflow);
            foreach ($reflection->getAttributes(TemporalTaskQueue::class) as $attribute) {
                $attributeInstance = $attribute->newInstance();
                assert($attributeInstance instanceof TemporalTaskQueue);

                foreach ($attributeInstance->name as $name) {
                    $this->addWorkerWorkflow($name, $workflow);
                }
            }
        }

        foreach ($this->data['activities'] as $activity) {
            $reflection = new \ReflectionClass($activity);
            foreach ($reflection->getAttributes(TemporalTaskQueue::class) as $attribute) {
                $attributeInstance = $attribute->newInstance();
                assert($attributeInstance instanceof TemporalTaskQueue);

                foreach ($attributeInstance->name as $name) {
                    $this->addWorkerActivity($name, $activity);
                }
            }
        }
    }

    private function addWorkerActivity(string $worker, string $activity): void
    {
        $this->data['worker_activities'][$worker][] = $activity;
    }

    private function addWorkerWorkflow(string $worker, string $workflow): void
    {
        $this->data['worker_workflows'][$worker][] = $workflow;
    }

    public function getWorkerActivities(string $worker): array
    {
        return $this->data['worker_activities'][$worker] ?? [];
    }

    public function getWorkerWorkflows(string $worker): array
    {
        return $this->data['worker_workflows'][$worker] ?? [];
    }

    public function getWorkers(): array
    {
        return $this->data['workers'];
    }

    public function getActivities(): array
    {
        return $this->data['activities'];
    }

    public function getWorkflows(): array
    {
        return $this->data['workflows'];
    }

    public function getName(): string
    {
        return 'fluffy_discord.roadrunner.temporal';
    }

    public static function getTemplate(): ?string
    {
        return '@FluffyDiscordRoadRunner/Collector/temporal.html.twig';
    }
}