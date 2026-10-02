<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\BatchIsolatingHostConnection;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Activity;
use Temporal\Activity\ActivityContextInterface;
use Temporal\Activity\ActivityInfo;
use Temporal\DataConverter\DataConverter;
use Temporal\Exception\Client\ActivityCanceledException;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\InvalidArgumentException;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkflowPanicPolicy;
use Temporal\WorkerFactory;

/**
 * TC-04 — the initializer creates one worker per task queue from config and registers the
 * workflows and activities assigned to that queue.
 */
class TemporalWorkerInitializerTest extends BaseTestCase
{
    private function realWorkerFactory(): WorkerFactory
    {
        return WorkerFactory::create(
            DataConverter::createDefault(),
            $this->createStub(RPCConnectionInterface::class),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $workerOptions
     */
    private function initializer(
        array                         $workerOptions = [],
        ?BatchIsolatingHostConnection $batchIsolatingHostConnection = null,
        ?LoggerInterface              $logger = null,
        ?HubInterface                 $sentryHub = null,
    ): TemporalWorkerInitializer
    {
        return new TemporalWorkerInitializer(
            $this->createStub(KernelInterface::class),
            $batchIsolatingHostConnection ?? $this->createStub(BatchIsolatingHostConnection::class),
            new ExceptionInterceptor([\Error::class]),
            new SimplePipelineProvider([]),
            $workerOptions,
            $logger,
            $sentryHub,
        );
    }

    public function testRegistersWorkflowAndActivityForMatchingQueue(): void
    {
        $initializer = $this->initializer();
        $initializer->addWorkflow(GreetingWorkflow::class, ['default']);
        $initializer->addActivity(GreetingActivity::class, ['default']);

        $workers = $initializer->initialize($this->realWorkerFactory());

        self::assertSame(['default'], array_keys($workers));

        $workflowClasses = array_map(
            static fn ($prototype) => $prototype->getClass()->getName(),
            iterator_to_array($workers['default']->getWorkflows()),
        );
        self::assertContains(GreetingWorkflow::class, $workflowClasses);

        $activityClasses = array_map(
            static fn ($prototype) => $prototype->getClass()->getName(),
            iterator_to_array($workers['default']->getActivities()),
        );
        self::assertContains(GreetingActivity::class, $activityClasses);
    }

    public function testEachQueueGetsItsOwnWorker(): void
    {
        $initializer = $this->initializer();
        $initializer->addWorkflow(GreetingWorkflow::class, ['billing-eu']);

        $workers = $initializer->initialize($this->realWorkerFactory());

        self::assertSame(['default', 'billing-eu'], array_keys($workers));
        self::assertCount(0, iterator_to_array($workers['default']->getWorkflows()));
        self::assertCount(1, iterator_to_array($workers['billing-eu']->getWorkflows()));
    }

    public function testDefaultQueueWorkerAlwaysExists(): void
    {
        $workers = $this->initializer()->initialize($this->realWorkerFactory());

        self::assertSame(['default'], array_keys($workers));
    }

    public function testConfiguredWorkerOptionsAreApplied(): void
    {
        $initializer = $this->initializer([
            'default' => [
                'maxConcurrentActivityExecutionSize' => 7,
                'workerStopTimeout'                  => '30 seconds',
                'stickyScheduleToStartTimeout'       => 5,
                'deadlockDetectionTimeout'           => '12',
                'workflowPanicPolicy'                => 'FailWorkflow',
            ],
        ]);

        $options = $initializer->initialize($this->realWorkerFactory())['default']->getOptions();

        self::assertSame(12, $options->deadlockDetectionTimeout?->s);
        self::assertSame(7, $options->maxConcurrentActivityExecutionSize);
        self::assertSame(30, $options->workerStopTimeout?->s);
        self::assertSame(5, $options->stickyScheduleToStartTimeout?->s);
        self::assertSame(WorkflowPanicPolicy::FailWorkflow, $options->workflowPanicPolicy);
    }

    protected function tearDown(): void
    {
        Activity::setCurrentContext(null);

        parent::tearDown();
    }

    private function enterActivityContext(int $attempt): void
    {
        $activityInfo = new ActivityInfo();
        $activityInfo->type->name = 'greeting.greet';
        $activityInfo->attempt = $attempt;

        $context = $this->createStub(ActivityContextInterface::class);
        $context->method('getInfo')->willReturn($activityInfo);

        Activity::setCurrentContext($context);
    }

    public function testFirstFailedAttemptIsLoggedAndReportedToSentry(): void
    {
        $failure = new \RuntimeException('SMTP down');
        $this->enterActivityContext(1);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Temporal: activity failed', self::callback(
            static fn (array $context): bool => $context['exception'] === $failure && $context['activity'] === 'greeting.greet' && $context['attempt'] === 1,
        ));

        $sentryHub = $this->createMock(HubInterface::class);
        $sentryHub->expects(self::once())->method('captureException')->with($failure);

        $this->initializer(logger: $logger, sentryHub: $sentryHub)->finalizeActivity($failure);
    }

    public function testRetriedAttemptIsLoggedButNotSentToSentryAgain(): void
    {
        $this->enterActivityContext(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $sentryHub = $this->createMock(HubInterface::class);
        $sentryHub->expects(self::never())->method('captureException');

        $this->initializer(logger: $logger, sentryHub: $sentryHub)->finalizeActivity(new \RuntimeException('SMTP down'));
    }

    public function testFailureWithoutActivityContextIsLoggedOnly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $sentryHub = $this->createMock(HubInterface::class);
        $sentryHub->expects(self::never())->method('captureException');

        $this->initializer(logger: $logger, sentryHub: $sentryHub)->finalizeActivity(new \RuntimeException('activity service failed to build'));
    }

    public function testActivityCancellationIsNotReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $this->initializer(logger: $logger)->finalizeActivity(new ActivityCanceledException());
    }

    public function testServicesAreResetEvenWhenReportingFails(): void
    {
        $batchIsolatingHostConnection = $this->createMock(BatchIsolatingHostConnection::class);
        $batchIsolatingHostConnection->expects(self::once())->method('resetServices');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \LogicException('logger broke'));

        $this->expectException(\LogicException::class);

        $this->initializer(batchIsolatingHostConnection: $batchIsolatingHostConnection, logger: $logger)->finalizeActivity(new \RuntimeException('failure'));
    }

    public function testSuccessfulActivityOnlyResetsServices(): void
    {
        $batchIsolatingHostConnection = $this->createMock(BatchIsolatingHostConnection::class);
        $batchIsolatingHostConnection->expects(self::once())->method('resetServices');
        $batchIsolatingHostConnection->expects(self::never())->method('recycleAfterBatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $this->initializer(batchIsolatingHostConnection: $batchIsolatingHostConnection, logger: $logger)->finalizeActivity(null);
    }

    public function testActivityExceptionKeepsTheWorker(): void
    {
        $batchIsolatingHostConnection = $this->createMock(BatchIsolatingHostConnection::class);
        $batchIsolatingHostConnection->expects(self::once())->method('resetServices');
        $batchIsolatingHostConnection->expects(self::never())->method('recycleAfterBatch');

        $this->initializer(batchIsolatingHostConnection: $batchIsolatingHostConnection)->finalizeActivity(new \RuntimeException('SMTP down'));
    }

    public function testActivityErrorRecyclesTheWorkerAfterThisJob(): void
    {
        $batchIsolatingHostConnection = $this->createMock(BatchIsolatingHostConnection::class);
        $batchIsolatingHostConnection->expects(self::once())->method('resetServices');
        $batchIsolatingHostConnection->expects(self::once())->method('recycleAfterBatch');

        $this->initializer(batchIsolatingHostConnection: $batchIsolatingHostConnection)->finalizeActivity(new \TypeError('corrupted state'));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function wrappedErrors(): iterable
    {
        $typeError = new \TypeError('corrupted state');

        yield 'non-retryable failure' => [new ApplicationFailure('corrupted state', \TypeError::class, true, previous: $typeError)];
        yield 'SDK argument wrapper' => [new InvalidArgumentException('corrupted state', previous: $typeError)];
    }

    #[DataProvider('wrappedErrors')]
    public function testWrappedActivityErrorRecyclesTheWorkerAfterThisJob(\Throwable $failure): void
    {
        $batchIsolatingHostConnection = $this->createMock(BatchIsolatingHostConnection::class);
        $batchIsolatingHostConnection->expects(self::once())->method('resetServices');
        $batchIsolatingHostConnection->expects(self::once())->method('recycleAfterBatch');

        $this->initializer(batchIsolatingHostConnection: $batchIsolatingHostConnection)->finalizeActivity($failure);
    }
}
