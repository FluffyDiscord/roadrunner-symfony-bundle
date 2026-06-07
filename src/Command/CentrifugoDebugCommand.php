<?php

namespace FluffyDiscord\RoadRunnerBundle\Command;

use FluffyDiscord\RoadRunnerBundle\EventListener\CentrifugoEventRouter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'centrifugo:debug', description: 'List the compile-time Centrifugo channel and RPC routing table (no server connection).')]
final class CentrifugoDebugCommand extends Command
{
    public function __construct(
        private readonly CentrifugoEventRouter $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $table = $this->router->getRoutingTable();

        $channelRows = [];
        foreach ($table['channels'] as $eventClass => $buckets) {
            foreach ($buckets['exact'] as $channel => $handlers) {
                foreach ($handlers as [$serviceId, $method, $priority]) {
                    $channelRows[] = [self::shortName($eventClass), $channel, $serviceId . '::' . $method, (string) $priority];
                }
            }
            foreach ($buckets['wildcard'] as $regex => $handlers) {
                foreach ($handlers as [$serviceId, $method, $priority]) {
                    $channelRows[] = [self::shortName($eventClass), self::regexToGlob($regex), $serviceId . '::' . $method, (string) $priority];
                }
            }
        }

        $rpcRows = [];
        foreach ($table['rpc']['exact'] as $rpcMethod => $handlers) {
            foreach ($handlers as [$serviceId, $method, $priority]) {
                $rpcRows[] = [$rpcMethod, $serviceId . '::' . $method, (string) $priority];
            }
        }

        if ($channelRows === [] && $rpcRows === []) {
            $io->writeln('No Centrifugo channel or RPC listeners are registered.');

            return Command::SUCCESS;
        }

        if ($channelRows !== []) {
            $io->section('Channel listeners');
            $io->table(['Event', 'Channel', 'Handler', 'Priority'], $channelRows);
        }

        if ($rpcRows !== []) {
            $io->section('RPC listeners');
            $io->table(['RPC method', 'Handler', 'Priority'], $rpcRows);
        }

        return Command::SUCCESS;
    }

    private static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private static function regexToGlob(string $regex): string
    {
        $inner = substr($regex, 2, -2);
        $inner = str_replace('.*', '*', $inner);

        return stripslashes($inner);
    }
}
