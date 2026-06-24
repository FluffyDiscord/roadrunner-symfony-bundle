<?php

namespace FluffyDiscord\RoadRunnerBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use Psr\Log\LoggerInterface;

/** See docs/specs/doctrine-preconnect.md. */
readonly class DoctrinePreconnectListener
{
    public function __construct(
        private ?ConnectionRegistry $registry,
        private ?LoggerInterface    $logger = null,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        if ($this->registry === null) {
            return;
        }

        try {
            $connections = $this->registry->getConnections();
        } catch (\Throwable $throwable) {
            $this->logger?->warning(
                'RoadRunner: unable to enumerate Doctrine connections for boot-time preconnect; skipping.',
                ['exception' => $throwable],
            );

            return;
        }

        foreach ($connections as $name => $connection) {
            if (!$connection instanceof Connection || !$this->isPostgres($connection)) {
                continue;
            }

            try {
                // Forces the lazy connection open; the returned handle is intentionally unused.
                $connection->getNativeConnection();
            } catch (\Throwable $throwable) {
                $this->logger?->warning(
                    'RoadRunner: PostgreSQL preconnect failed for Doctrine connection "{connection}"; it will be retried lazily on first use.',
                    // (string) guards numeric connection names: PHP coerces int-like array keys to int.
                    ['connection' => (string) $name, 'exception' => $throwable],
                );
            }
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
