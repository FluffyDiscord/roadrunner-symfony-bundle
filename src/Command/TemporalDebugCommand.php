<?php

namespace FluffyDiscord\RoadRunnerBundle\Command;

use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'debug:temporal', description: 'Display registered Temporal task queues, workflows, activities and activity stubs (no server connection).')]
class TemporalDebugCommand
{
    public function __construct(
        private readonly TemporalIntrospectorInterface $introspector,
    )
    {
    }

    public function __invoke(
        SymfonyStyle        $io,

        #[Option(description: 'Output format: txt, json, or mermaid (workflow -> activity flowchart)')]
        TemporalDebugFormat $format = TemporalDebugFormat::Txt,
    ): int
    {
        match ($format) {
            TemporalDebugFormat::Txt     => $this->renderText($io),
            TemporalDebugFormat::Json    => $io->writeln(json_encode($this->getReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            TemporalDebugFormat::Mermaid => $io->writeln($this->getMermaidFlowchart()),
        };

        return Command::SUCCESS;
    }

    private function renderText(SymfonyStyle $io): void
    {
        $workflowsByQueue = $this->introspector->workflowsByQueue();
        $activitiesByQueue = $this->introspector->activitiesByQueue();
        $optionsByQueue = $this->getWorkerOptionsByQueue();

        foreach ($this->introspector->queues() as $queue) {
            $io->section($queue);

            $options = $optionsByQueue[$queue] ?? [];
            $io->writeln($options === [] ? 'Worker options: SDK defaults' : 'Worker options: ' . json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $workflowRows = [];
            foreach ($workflowsByQueue[$queue] ?? [] as $class) {
                $workflowRows[] = [
                    TemporalIntrospector::shortName($class),
                    $this->introspector->workflowId($class) ?? '-',
                    $this->renderStubs($class),
                ];
            }
            if ($workflowRows !== []) {
                $io->table(['Workflow', 'ID', 'Stubs'], $workflowRows);
            }

            $activityRows = [];
            foreach ($activitiesByQueue[$queue] ?? [] as $class) {
                $ids = $this->introspector->activityIds($class);
                $activityRows[] = [
                    TemporalIntrospector::shortName($class),
                    $ids === [] ? '-' : implode(', ', $ids),
                ];
            }
            if ($activityRows !== []) {
                $io->table(['Activity', 'IDs'], $activityRows);
            }
        }
    }

    /** @param class-string $workflowClass */
    private function renderStubs(string $workflowClass): string
    {
        $lines = [];
        foreach ($this->introspector->stubs($workflowClass) as $stub) {
            $lines[] = sprintf(
                '%s -> %s | %s | retry=%s',
                $stub['activityShort'],
                $stub['resolvedQueue'],
                $stub['startToClose'],
                $stub['retry'],
            );
        }

        return $lines === [] ? '-' : implode("\n", $lines);
    }

    /**
     * @return array<string, array{options: array<string, mixed>, workflows: list<array{class: string, id: ?string, stubs: list<array{property: string, activity: string, queue: string, startToClose: string, retry: string}>}>, activities: list<array{class: string, ids: list<string>}>}>
     */
    private function getReport(): array
    {
        $workflowsByQueue = $this->introspector->workflowsByQueue();
        $activitiesByQueue = $this->introspector->activitiesByQueue();
        $optionsByQueue = $this->getWorkerOptionsByQueue();

        $report = [];
        foreach ($this->introspector->queues() as $queue) {
            $workflows = [];
            foreach ($workflowsByQueue[$queue] ?? [] as $class) {
                $stubs = [];
                foreach ($this->introspector->stubs($class) as $stub) {
                    $stubs[] = [
                        'property'     => $stub['property'],
                        'activity'     => $stub['activity'],
                        'queue'        => $stub['resolvedQueue'],
                        'startToClose' => $stub['startToClose'],
                        'retry'        => $stub['retry'],
                    ];
                }
                $workflows[] = ['class' => $class, 'id' => $this->introspector->workflowId($class), 'stubs' => $stubs];
            }

            $activities = [];
            foreach ($activitiesByQueue[$queue] ?? [] as $class) {
                $activities[] = ['class' => $class, 'ids' => $this->introspector->activityIds($class)];
            }

            $report[$queue] = [
                'options'    => $optionsByQueue[$queue] ?? [],
                'workflows'  => $workflows,
                'activities' => $activities,
            ];
        }

        return $report;
    }

    private function getMermaidFlowchart(): string
    {
        $nodesByQueue = [];
        $edges = [];

        foreach ($this->introspector->workflowsByQueue() as $queue => $workflows) {
            foreach ($workflows as $workflowClass) {
                $workflow = TemporalIntrospector::shortName($workflowClass);
                $nodesByQueue[$queue][] = $workflow;

                foreach ($this->introspector->stubs($workflowClass) as $stub) {
                    $resolvedQueue = $stub['stub']->queue ?? $queue;
                    $nodesByQueue[$resolvedQueue][] = $stub['activityShort'];
                    $edges[] = sprintf(
                        '  %s -->|"%s | %s"| %s',
                        $workflow,
                        $stub['resolvedQueue'],
                        $stub['startToClose'],
                        $stub['activityShort'],
                    );
                }
            }
        }

        foreach ($this->introspector->activitiesByQueue() as $queue => $activities) {
            foreach ($activities as $activityClass) {
                $nodesByQueue[$queue][] = TemporalIntrospector::shortName($activityClass);
            }
        }

        $lines = ['flowchart LR'];
        foreach ($nodesByQueue as $queue => $nodes) {
            $lines[] = sprintf('  subgraph %s', $this->getMermaidNodeId((string) $queue));
            foreach (array_values(array_unique($nodes)) as $node) {
                $lines[] = '    ' . $node;
            }
            $lines[] = '  end';
        }

        return implode("\n", [...$lines, ...array_values(array_unique($edges))]);
    }

    private function getMermaidNodeId(string $queue): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $queue);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getWorkerOptionsByQueue(): array
    {
        $optionsByQueue = [];
        foreach ($this->introspector->workerSummaries() as $summary) {
            $optionsByQueue[$summary['taskQueue']] = $summary['options'];
        }

        return $optionsByQueue;
    }
}
