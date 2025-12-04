<?php

namespace FluffyDiscord\RoadRunnerBundle\DataCollector;

use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Internal\Declaration\Prototype\ActivityPrototype;
use Temporal\Internal\Declaration\Prototype\WorkflowPrototype;

final class TemporalCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TemporalWorkerFactoryInterface $temporalWorkerFactory,
        private readonly TemporalWorkerInitializer      $temporalWorkerInitializer,
    )
    {

    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'activities' => [],
            'workflows'  => [],
            'workers'    => [],
        ];

        $workerFactory = $this->temporalWorkerFactory->create();

        $workers = $this->temporalWorkerInitializer->initialize($workerFactory);

        foreach ($workers as $worker) {
            $this->data['workers'][] = [
                'class'      => $worker['factory']::class,
                'id'         => $worker['worker']->getId(),
                'activities' => array_map(
                    static fn(ActivityPrototype $activity) => [
                        'class' => $activity->getClass()->getName(),
                        'id'    => $activity->getID(),
                    ],
                    iterator_to_array($worker['worker']->getActivities()),
                ),
                'workflows'  => array_map(
                    static fn(WorkflowPrototype $workflow) => [
                        'class' => $workflow->getClass()->getName(),
                        'id'    => $workflow->getID(),
                    ],
                    iterator_to_array($worker['worker']->getWorkflows()),
                ),
            ];

            foreach ($worker['worker']->getActivities() as $activity) {
                $this->data['activities'][$activity->getClass()->getName()] = [
                    'class'      => $activity->getClass()->getName(),
                    'id'         => $activity->getID(),
                    'taskQueues' => array_unique([...$this->data['activities'][$activity->getClass()->getName()] ?? [], $worker['worker']->getId()]),
                ];
            }

            foreach ($worker['worker']->getWorkflows() as $workflow) {
                $this->data['workflows'][$workflow->getClass()->getName()] = [
                    'class'      => $workflow->getClass()->getName(),
                    'id'         => $workflow->getID(),
                    'taskQueues' => array_unique([...$this->data['workflows'][$workflow->getClass()->getName()] ?? [], $worker['worker']->getId()]),
                ];
            }
        }
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