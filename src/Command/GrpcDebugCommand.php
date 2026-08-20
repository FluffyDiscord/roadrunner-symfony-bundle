<?php

namespace FluffyDiscord\RoadRunnerBundle\Command;

use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcServerFacts;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcServiceDebugRow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'grpc:debug', description: 'List the registered gRPC services, their methods and the RoadRunner gRPC server facts from .rr.yaml (no server connection).')]
class GrpcDebugCommand extends Command
{
    public function __construct(
        private readonly GrpcIntrospector $introspector,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->renderServer($io, $this->introspector->getServerFacts());

        $rows = $this->introspector->describe();

        if ($rows === []) {
            $io->warning('No gRPC services registered. Implement a protoc-gen-php-grpc generated *Interface in an autoconfigured service, or tag it with fluffy_discord.roadrunner.grpc.service.');

            return Command::SUCCESS;
        }

        $hasInvalidMethods = false;

        foreach ($this->groupByService($rows) as $serviceRows) {
            $this->renderService($io, $serviceRows);
        }

        foreach ($rows as $row) {
            if (!$row->isValid()) {
                $hasInvalidMethods = true;
            }
        }

        if ($hasInvalidMethods) {
            $this->renderInvalidMethods($io, $rows);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function renderServer(SymfonyStyle $io, GrpcServerFacts $facts): void
    {
        $io->section('Server (.rr.yaml)');

        if (!$facts->isConfigured) {
            $io->text('grpc section not found in .rr.yaml');

            return;
        }

        $io->definitionList(
            ['listen' => $facts->listen ?? '-'],
            ['TLS' => $facts->tlsEnabled ? 'on' : 'off'],
            ['client_auth_type' => $facts->clientAuthType ?? '-'],
            ['proto' => $facts->protoFiles === [] ? '-' : implode(', ', $facts->protoFiles)],
            ['security' => $this->introspector->isSecurityEnabled() ? 'enabled (fluffy_discord_road_runner.grpc.security)' : 'disabled'],
        );
    }

    /**
     * @param list<GrpcServiceDebugRow> $serviceRows
     */
    private function renderService(SymfonyStyle $io, array $serviceRows): void
    {
        $first = $serviceRows[0];
        $io->section(sprintf('Service %s — %s → %s', $first->serviceName, $first->interface, $first->handlerClass));

        $tableRows = [];

        foreach ($serviceRows as $row) {
            $tableRows[] = [$row->methodName, $row->inputType, $row->outputType, $this->describeAccess($row)];
        }

        $io->table(['Method', 'Input', 'Output', 'Access'], $tableRows);
    }

    /**
     * @param list<GrpcServiceDebugRow> $rows
     */
    private function renderInvalidMethods(SymfonyStyle $io, array $rows): void
    {
        $io->section('Invalid gRPC method signatures');

        $tableRows = [];

        foreach ($rows as $row) {
            if ($row->isValid()) {
                continue;
            }

            $tableRows[] = [$row->serviceName, $row->methodName, $row->invalidReason];
        }

        $io->table(['Service', 'Method', 'Problem'], $tableRows);
    }

    private function describeAccess(GrpcServiceDebugRow $row): string
    {
        if ($row->accessAttributes === []) {
            return '-';
        }

        $label = implode(', ', $row->accessAttributes);

        if ($this->introspector->isSecurityEnabled()) {
            return $label;
        }

        return $label . ' (unenforced — grpc.security.enabled: false)';
    }

    /**
     * @param list<GrpcServiceDebugRow> $rows
     * @return list<list<GrpcServiceDebugRow>>
     */
    private function groupByService(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->serviceName . '|' . $row->handlerClass][] = $row;
        }

        return array_values($grouped);
    }
}
