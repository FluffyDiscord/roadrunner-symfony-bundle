<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\RecordingBatchIsolatingHostConnection;
use Psr\Log\LoggerInterface;
use Sentry\ClientInterface as SentryClientInterface;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\EnvironmentInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Temporal\Internal\Support\Facade;
use Temporal\Worker\Transport\CommandBatch;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;

class BatchIsolatingHostConnectionTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Workflow::setCurrentContext(null);

        parent::tearDown();
    }

    private function createConnection(
        HostConnectionInterface    $hostConnection,
        ?ServicesResetterInterface $servicesResetter = null,
        ?LoggerInterface           $logger = null,
        ?SentryHubInterface        $sentryHub = null,
    ): RecordingBatchIsolatingHostConnection
    {
        return new RecordingBatchIsolatingHostConnection(
            $hostConnection,
            $this->createStub(EnvironmentInterface::class),
            $servicesResetter ?? $this->createStub(ServicesResetterInterface::class),
            $logger,
            $sentryHub,
        );
    }

    private function createHostConnectionWithABatch(): HostConnectionInterface
    {
        $hostConnection = $this->createStub(HostConnectionInterface::class);
        $hostConnection->method('waitBatch')->willReturn(new CommandBatch('[]', []));

        return $hostConnection;
    }

    private function enterWorkflowContext(): void
    {
        Workflow::setCurrentContext($this->createStub(WorkflowContextInterface::class));
    }

    private function createSentryHubExpectingOneBatch(): SentryHubInterface
    {
        $sentryClient = $this->createMock(SentryClientInterface::class);
        $sentryClient->expects(self::once())->method('flush');

        $sentryHub = $this->createMock(SentryHubInterface::class);
        $sentryHub->expects(self::once())->method('pushScope');
        $sentryHub->expects(self::once())->method('popScope');
        $sentryHub->method('getClient')->willReturn($sentryClient);

        return $sentryHub;
    }

    private function createFailingServicesResetter(): ServicesResetterInterface
    {
        $servicesResetter = $this->createStub(ServicesResetterInterface::class);
        $servicesResetter->method('reset')->willThrowException(new \RuntimeException('buffered handler lost its socket'));

        return $servicesResetter;
    }

    public function testBatchRunsInItsOwnSentryScopeAndEndsWithCleanServices(): void
    {
        $hostConnection = $this->createMock(HostConnectionInterface::class);
        $hostConnection->method('waitBatch')->willReturn(new CommandBatch('[]', []));
        $hostConnection->expects(self::once())->method('send')->with('frame');

        $servicesResetter = $this->createMock(ServicesResetterInterface::class);
        $servicesResetter->expects(self::once())->method('reset');

        $connection = $this->createConnection($hostConnection, $servicesResetter, sentryHub: $this->createSentryHubExpectingOneBatch());

        $connection->waitBatch();
        $this->enterWorkflowContext();
        $connection->send('frame');

        self::assertNull(Facade::getCurrentContext());
        self::assertSame(0, $connection->handedBatchCount);
    }

    public function testFailedBatchEndsWithCleanServices(): void
    {
        $failure = new \RuntimeException('decode failed');

        $hostConnection = $this->createMock(HostConnectionInterface::class);
        $hostConnection->method('waitBatch')->willReturn(new CommandBatch('[]', []));
        $hostConnection->expects(self::once())->method('error')->with($failure);

        $servicesResetter = $this->createMock(ServicesResetterInterface::class);
        $servicesResetter->expects(self::once())->method('reset');

        $connection = $this->createConnection($hostConnection, $servicesResetter, sentryHub: $this->createSentryHubExpectingOneBatch());

        $connection->waitBatch();
        $this->enterWorkflowContext();
        $connection->error($failure);

        self::assertNull(Facade::getCurrentContext());
    }

    public function testFailedResetNeverTurnsASentResponseIntoAnError(): void
    {
        $hostConnection = $this->createMock(HostConnectionInterface::class);
        $hostConnection->expects(self::once())->method('send');
        $hostConnection->expects(self::never())->method('error');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        $this->createConnection($hostConnection, $this->createFailingServicesResetter(), $logger)->send('frame');
    }

    public function testFailedResetHandsTheNextJobToAFreshWorker(): void
    {
        $connection = $this->createConnection($this->createHostConnectionWithABatch(), $this->createFailingServicesResetter());
        $connection->send('frame');

        self::assertNull($connection->waitBatch());
        self::assertSame(1, $connection->handedBatchCount);
    }

    public function testRequestedRecycleHandsTheNextJobToAFreshWorker(): void
    {
        $connection = $this->createConnection($this->createHostConnectionWithABatch());
        $connection->recycleAfterBatch();

        self::assertNull($connection->waitBatch());
        self::assertSame(1, $connection->handedBatchCount);
    }

    public function testSentryScopeIsClosedEvenWhenFlushFails(): void
    {
        $sentryClient = $this->createStub(SentryClientInterface::class);
        $sentryClient->method('flush')->willThrowException(new \RuntimeException('transport down'));

        $sentryHub = $this->createMock(SentryHubInterface::class);
        $sentryHub->method('getClient')->willReturn($sentryClient);
        $sentryHub->expects(self::once())->method('popScope');

        $this->createConnection($this->createStub(HostConnectionInterface::class), sentryHub: $sentryHub)->send('frame');
    }

    public function testServicesAreResetEvenWhenSendingFails(): void
    {
        $hostConnection = $this->createStub(HostConnectionInterface::class);
        $hostConnection->method('send')->willThrowException(new \RuntimeException('pipe closed'));

        $servicesResetter = $this->createMock(ServicesResetterInterface::class);
        $servicesResetter->expects(self::once())->method('reset');

        $this->expectExceptionMessage('pipe closed');

        $this->createConnection($hostConnection, $servicesResetter)->send('frame');
    }

    public function testNoScopeIsOpenedWhenTheWorkerStops(): void
    {
        $hostConnection = $this->createStub(HostConnectionInterface::class);
        $hostConnection->method('waitBatch')->willReturn(null);

        $sentryHub = $this->createMock(SentryHubInterface::class);
        $sentryHub->expects(self::never())->method('pushScope');

        $connection = $this->createConnection($hostConnection, sentryHub: $sentryHub);

        self::assertNull($connection->waitBatch());
        self::assertSame(0, $connection->handedBatchCount);
    }
}
