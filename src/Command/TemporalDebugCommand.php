<?php

namespace FluffyDiscord\RoadRunnerBundle\Command;

use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'temporal:debug', description: 'List registered Temporal workflows and activities per task queue (no server connection).')]
final class TemporalDebugCommand extends Command
{
    public function __construct(
        private readonly TemporalIntrospectorInterface $introspector,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workflowsByQueue = $this->introspector->workflowsByQueue();
        $activitiesByQueue = $this->introspector->activitiesByQueue();

        if ($workflowsByQueue === [] && $activitiesByQueue === []) {
            $io->writeln('No workflows or activities are registered.');

            return Command::SUCCESS;
        }

        foreach ($this->introspector->queues() as $queue) {
            $io->section($queue);

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

        return Command::SUCCESS;
    }

    /** @param class-string $workflowClass */
    private function renderStubs(string $workflowClass): string
    {
        $lines = [];
        foreach ($this->introspector->stubs($workflowClass) as $stub) {
            $prefix = $stub['typed'] ? '[TYPED!] ' : '';
            $lines[] = sprintf(
                '%s%s -> %s | %s | retry=%s',
                $prefix,
                $stub['activityShort'],
                $stub['resolvedQueue'],
                $stub['startToClose'],
                $stub['retry'],
            );
        }

        return $lines === [] ? '-' : implode("\n", $lines);
    }
}
