<?php

namespace FluffyDiscord\RoadRunnerBundle\Command;

use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'temporal:diagram', description: 'Render a Mermaid flowchart of workflow -> activity stub edges (no server connection).')]
final class TemporalDiagramCommand extends Command
{
    public function __construct(
        private readonly TemporalIntrospector $introspector,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the diagram to this file instead of stdout.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $diagram = $this->build();

        $file = $input->getOption('output');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $diagram . "\n");
            (new SymfonyStyle($input, $output))->success(sprintf('Diagram written to %s', $file));

            return Command::SUCCESS;
        }

        $output->writeln($diagram);

        return Command::SUCCESS;
    }

    private function build(): string
    {
        $byQueue = [];
        $edges = [];

        foreach ($this->introspector->workflowsByQueue() as $queue => $workflows) {
            foreach ($workflows as $workflowClass) {
                $workflow = TemporalIntrospector::shortName($workflowClass);
                $byQueue[$queue][] = $workflow;

                foreach ($this->introspector->stubs($workflowClass) as $stub) {
                    $resolvedQueue = $stub['stub']->queue ?? $queue;
                    $byQueue[$resolvedQueue][] = $stub['activityShort'];
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
                $byQueue[$queue][] = TemporalIntrospector::shortName($activityClass);
            }
        }

        $lines = ['flowchart LR'];
        foreach ($byQueue as $queue => $nodes) {
            $lines[] = sprintf('  subgraph %s', self::nodeId($queue));
            foreach (array_values(array_unique($nodes)) as $node) {
                $lines[] = '    ' . $node;
            }
            $lines[] = '  end';
        }

        return implode("\n", [...$lines, ...array_values(array_unique($edges))]);
    }

    private static function nodeId(string $queue): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $queue);
    }
}
