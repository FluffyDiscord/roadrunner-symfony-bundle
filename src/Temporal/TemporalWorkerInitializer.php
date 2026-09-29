<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface as SentryHubInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\BatchIsolatingHostConnection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Activity;
use Temporal\Activity\ActivityInfo;
use Temporal\Exception\Client\ActivityCanceledException;
use Temporal\Exception\Client\ActivityPausedException;
use Temporal\Exception\Client\ActivityWorkerShutdownException;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Exception\OutOfContextException;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Internal\Support\DateInterval;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;

/**
 * @internal
 */
class TemporalWorkerInitializer
{
    /** @var array<string, list<class-string>> */
    private array $activities = [];

    /** @var array<string, list<class-string>> */
    private array $workflows = [];

    /**
     * @param array<string, array<string, mixed>> $workerOptions
     */
    public function __construct(
        private readonly KernelInterface               $kernel,

        #[Autowire(lazy: true)]
        private readonly BatchIsolatingHostConnection  $batchIsolatingHostConnection,

        private readonly ExceptionInterceptorInterface $exceptionInterceptor,
        private readonly PipelineProvider              $pipelineProvider,
        private readonly array                         $workerOptions = [],
        private readonly ?LoggerInterface              $logger = null,
        private readonly ?SentryHubInterface           $sentryHub = null,
    )
    {
    }

    /**
     * @param class-string $activity
     * @param list<string> $taskQueues
     */
    public function addActivity(string $activity, array $taskQueues): void
    {
        foreach ($taskQueues as $taskQueue) {
            if (in_array($activity, $this->activities[$taskQueue] ?? [], true)) {
                continue;
            }
            $this->activities[$taskQueue][] = $activity;
        }
    }

    /**
     * @param class-string $workflow
     * @param list<string> $taskQueues
     */
    public function addWorkflow(string $workflow, array $taskQueues): void
    {
        foreach ($taskQueues as $taskQueue) {
            if (in_array($workflow, $this->workflows[$taskQueue] ?? [], true)) {
                continue;
            }
            $this->workflows[$taskQueue][] = $workflow;
        }
    }

    /**
     * @return array<string, list<class-string>>
     */
    public function getRegisteredWorkflows(): array
    {
        return $this->workflows;
    }

    /**
     * @return array<string, list<class-string>>
     */
    public function getRegisteredActivities(): array
    {
        return $this->activities;
    }

    /**
     * @return list<string>
     */
    public function getTaskQueues(): array
    {
        $taskQueues = [
            WorkerFactoryInterface::DEFAULT_TASK_QUEUE,
            ...array_keys($this->workflows),
            ...array_keys($this->activities),
        ];

        return array_values(array_unique(array_map(strval(...), $taskQueues)));
    }

    /**
     * @return list<array{taskQueue: string, options: array<string, mixed>}>
     */
    public function getWorkerSummaries(): array
    {
        $summaries = [];
        foreach ($this->getTaskQueues() as $taskQueue) {
            $summaries[] = ['taskQueue' => $taskQueue, 'options' => $this->workerOptions[$taskQueue] ?? []];
        }

        return $summaries;
    }

    /**
     * @return array<string, WorkerInterface>
     */
    public function initialize(WorkerFactoryInterface $workerFactory): array
    {
        $workers = [];

        foreach ($this->getTaskQueues() as $taskQueue) {
            $worker = $workerFactory->newWorker(
                $taskQueue,
                $this->createWorkerOptions($this->workerOptions[$taskQueue] ?? []),
                $this->exceptionInterceptor,
                $this->pipelineProvider,
                $this->logger,
            );

            $worker->registerActivityFinalizer($this->finalizeActivity(...));

            foreach ($this->workflows[$taskQueue] ?? [] as $workflow) {
                $worker->registerWorkflowTypes($workflow);
            }

            foreach ($this->activities[$taskQueue] ?? [] as $activity) {
                $worker->registerActivity(
                    $activity,
                    fn(\ReflectionClass $class) => $this->kernel->getContainer()->get($class->getName()),
                );
            }

            $workers[$taskQueue] = $worker;
        }

        return $workers;
    }

    public function finalizeActivity(?\Throwable $failure = null): void
    {
        try {
            $this->reportActivityFailure($failure);
        } finally {
            if ($failure instanceof \Error) {
                $this->batchIsolatingHostConnection->recycleAfterBatch();
            }

            $this->batchIsolatingHostConnection->resetServices();
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createWorkerOptions(array $options): WorkerOptions
    {
        $workerOptions = WorkerOptions::new();

        foreach ($options as $propertyName => $value) {
            $workerOptions->{$propertyName} = $this->getWorkerOptionValue($propertyName, $value);
        }

        return $workerOptions;
    }

    private function getWorkerOptionValue(string $propertyName, mixed $value): mixed
    {
        $type = (new \ReflectionProperty(WorkerOptions::class, $propertyName))->getType();
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

        if ($typeName === \DateInterval::class) {
            $isNumericSeconds = is_numeric($value);
            $duration = $isNumericSeconds ? (int) $value : $value;

            return DateInterval::parse($duration, DateInterval::FORMAT_SECONDS);
        }

        $isEnum = is_a($typeName, \UnitEnum::class, true);
        if ($isEnum) {
            return $this->getEnumCase($typeName, $value);
        }

        return $value;
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    private function getEnumCase(string $enumClass, mixed $caseName): \UnitEnum
    {
        foreach ($enumClass::cases() as $case) {
            if ($case->name === $caseName) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('"%s" has no case named %s.', $enumClass, json_encode($caseName, JSON_THROW_ON_ERROR)));
    }

    private function reportActivityFailure(?\Throwable $failure): void
    {
        if ($failure === null) {
            return;
        }

        $isInterruption = $failure instanceof ActivityCanceledException
            || $failure instanceof ActivityPausedException
            || $failure instanceof ActivityWorkerShutdownException;
        if ($isInterruption) {
            return;
        }

        $activityInfo = $this->getCurrentActivityInfo();

        $this->logger?->error('Temporal: activity failed', [
            'exception'  => $failure,
            'activity'   => $activityInfo?->type->name,
            'attempt'    => $activityInfo?->attempt,
            'workflowId' => $activityInfo?->workflowExecution?->getID(),
            'taskQueue'  => $activityInfo?->taskQueue,
        ]);

        $isFirstAttempt = $activityInfo?->attempt === 1;
        if ($isFirstAttempt) {
            $this->sentryHub?->captureException($failure);
        }
    }

    private function getCurrentActivityInfo(): ?ActivityInfo
    {
        try {
            return Activity::getInfo();
        } catch (OutOfContextException) {
            return null;
        }
    }
}
