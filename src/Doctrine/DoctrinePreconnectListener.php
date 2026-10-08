<?php

namespace FluffyDiscord\RoadRunnerBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use Psr\Log\LoggerInterface;

readonly class DoctrinePreconnectListener
{
    /**
     * @param list<string>|null $connectionNames
     */
    public function __construct(
        private ?ConnectionRegistry $registry,
        private ?LoggerInterface    $logger = null,
        private ?array              $connectionNames = null,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        if ($this->registry === null) {
            return;
        }

        $existingConnectionNames = $this->getExistingConnectionNames($this->registry);
        $connectionNames = $this->connectionNames ?? $existingConnectionNames;

        foreach ($connectionNames as $connectionName) {
            $connectionExists = in_array($connectionName, $existingConnectionNames, true);
            if (!$connectionExists) {
                $this->logger?->warning(
                    'RoadRunner: doctrine.preconnect names Doctrine connection "{connection}", which does not exist; known: {known}.',
                    ['connection' => $connectionName, 'known' => implode(', ', $existingConnectionNames)],
                );

                continue;
            }

            $this->preconnect($this->registry, $connectionName);
        }
    }

    /**
     * @return list<string>
     */
    private function getExistingConnectionNames(ConnectionRegistry $registry): array
    {
        $connectionNamesAsKeys = array_keys($registry->getConnectionNames());

        return array_map(strval(...), $connectionNamesAsKeys);
    }

    private function preconnect(ConnectionRegistry $registry, string $connectionName): void
    {
        try {
            $connection = $registry->getConnection($connectionName);
            if (!$connection instanceof Connection || !$this->isPostgres($connection)) {
                return;
            }

            $connection->getNativeConnection();
        } catch (\Throwable $throwable) {
            $this->logger?->warning(
                'RoadRunner: PostgreSQL preconnect failed for Doctrine connection "{connection}"; it will be retried lazily on first use.',
                ['connection' => $connectionName, 'exception' => $throwable],
            );
        }
    }

    private function isPostgres(Connection $connection): bool
    {
        // Driver NAME, not getDriver(): doctrine-bridge/doctrine-bundle wrap the driver in
        // middleware, so getDriver() is never AbstractPostgreSQLDriver. The name is socket-free.
        $driver = $connection->getParams()['driver'] ?? null;

        return $driver === 'pdo_pgsql' || $driver === 'pgsql';
    }
}
