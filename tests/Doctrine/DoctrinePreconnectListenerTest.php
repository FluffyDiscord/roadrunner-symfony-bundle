<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RoadRunnerBundle\Doctrine\DoctrinePreconnectListener;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/** See docs/specs/doctrine-preconnect.md §9. */
#[AllowMockObjectsWithoutExpectations]
class DoctrinePreconnectListenerTest extends BaseTestCase
{
    /**
     * @param array<string, mixed> $params
     * @return Connection&MockObject
     */
    private function connection(array $params, ?Driver $driver = null): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn($params);
        if ($driver !== null) {
            $connection->method('getDriver')->willReturn($driver);
        }

        return $connection;
    }

    /** @param array<string, object> $connections */
    private function registry(array $connections): ConnectionRegistry&MockObject
    {
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnections')->willReturn($connections);

        return $registry;
    }

    private function dispatch(?ConnectionRegistry $registry, ?LoggerInterface $logger): void
    {
        (new DoctrinePreconnectListener($registry, $logger))->__invoke(new WorkerBootingEvent());
    }

    public function testPdoPostgresConnectionIsPreconnected(): void
    {
        $connection = $this->connection(['driver' => 'pdo_pgsql']);
        $connection->expects($this->once())->method('getNativeConnection')->willReturn(new \stdClass());

        $this->dispatch($this->registry(['default' => $connection]), null);
    }

    public function testNativePgsqlConnectionIsPreconnected(): void
    {
        $connection = $this->connection(['driver' => 'pgsql']);
        $connection->expects($this->once())->method('getNativeConnection')->willReturn(new \stdClass());

        $this->dispatch($this->registry(['default' => $connection]), null);
    }

    public function testMysqlConnectionIsNotPreconnectedAndProducesNoWarning(): void
    {
        $connection = $this->connection(['driver' => 'pdo_mysql']);
        $connection->expects($this->never())->method('getNativeConnection');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->dispatch($this->registry(['default' => $connection]), $logger);
    }

    public function testSqliteConnectionIsNotPreconnected(): void
    {
        $connection = $this->connection(['driver' => 'pdo_sqlite']);
        $connection->expects($this->never())->method('getNativeConnection');

        $this->dispatch($this->registry(['default' => $connection]), null);
    }

    public function testConnectionWithoutADriverNameIsNotPreconnected(): void
    {
        $connection = $this->connection([]);
        $connection->expects($this->never())->method('getNativeConnection');

        $this->dispatch($this->registry(['default' => $connection]), null);
    }

    public function testOnlyPostgresIsPreconnectedInAMixedRegistry(): void
    {
        $postgres = $this->connection(['driver' => 'pdo_pgsql']);
        $postgres->expects($this->once())->method('getNativeConnection')->willReturn(new \stdClass());

        $mysql = $this->connection(['driver' => 'pdo_mysql']);
        $mysql->expects($this->never())->method('getNativeConnection');

        $sqlite = $this->connection(['driver' => 'pdo_sqlite']);
        $sqlite->expects($this->never())->method('getNativeConnection');

        $this->dispatch($this->registry([
            'default' => $postgres,
            'legacy'  => $mysql,
            'cache'   => $sqlite,
        ]), null);
    }

    public function testMiddlewareWrappedPostgresIsStillDetected(): void
    {
        // Guards the live-E2E regression: detection must use params['driver'], not getDriver(),
        // which doctrine-bundle wraps in middleware. Fails if anyone reverts to getDriver().
        $middlewareWrappedDriver = new class (new PgSQLDriver()) extends AbstractDriverMiddleware {};

        $connection = $this->connection(['driver' => 'pdo_pgsql'], $middlewareWrappedDriver);
        $connection->expects($this->once())->method('getNativeConnection')->willReturn(new \stdClass());

        $this->dispatch($this->registry(['default' => $connection]), null);
    }

    public function testFailedPreconnectIsLoggedAndSwallowed(): void
    {
        $connection = $this->connection(['driver' => 'pdo_pgsql']);
        $connection->method('getNativeConnection')->willThrowException(new \RuntimeException('connection refused'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('preconnect failed'),
                $this->callback(static fn (array $context): bool => ($context['connection'] ?? null) === 'default'),
            );

        $this->dispatch($this->registry(['default' => $connection]), $logger);
    }

    public function testFailedPreconnectWithoutLoggerDoesNotThrow(): void
    {
        $connection = $this->connection(['driver' => 'pdo_pgsql']);
        $connection->method('getNativeConnection')->willThrowException(new \RuntimeException('connection refused'));

        $this->dispatch($this->registry(['default' => $connection]), null);

        $this->expectNotToPerformAssertions();
    }

    public function testNullRegistryIsANoOp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->dispatch(null, $logger);
    }

    public function testNonConnectionEntriesAreSkipped(): void
    {
        $postgres = $this->connection(['driver' => 'pdo_pgsql']);
        $postgres->expects($this->once())->method('getNativeConnection')->willReturn(new \stdClass());

        $this->dispatch($this->registry([
            'odm'     => new \stdClass(),
            'default' => $postgres,
        ]), null);
    }

    public function testFailureOnOneConnectionDoesNotStopTheOthers(): void
    {
        $failing = $this->connection(['driver' => 'pdo_pgsql']);
        $failing->method('getNativeConnection')->willThrowException(new \RuntimeException('down'));

        $healthy = $this->connection(['driver' => 'pdo_pgsql']);
        $healthy->expects($this->once())->method('getNativeConnection')->willReturn(new \stdClass());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->dispatch($this->registry([
            'broken'  => $failing,
            'default' => $healthy,
        ]), $logger);
    }

    public function testFailureToEnumerateConnectionsIsLoggedAndSwallowed(): void
    {
        $registry = $this->createMock(ConnectionRegistry::class);
        $registry->method('getConnections')->willThrowException(new \RuntimeException('container error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->callback(static fn (string $message): bool =>
                str_contains($message, 'unable to enumerate') && stripos($message, 'postgres') === false));

        $this->dispatch($registry, $logger);
    }
}
