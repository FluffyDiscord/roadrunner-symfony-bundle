<?php

namespace FluffyDiscord\RoadRunnerBundle\DataCollector;

use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospectorInterface;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Client\Workflow\WorkflowExecutionDescription;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Interceptor\Trait\WorkflowClientCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowClient\CancelInput;
use Temporal\Interceptor\WorkflowClient\DescribeInput;
use Temporal\Interceptor\WorkflowClient\GetResultInput;
use Temporal\Interceptor\WorkflowClient\QueryInput;
use Temporal\Interceptor\WorkflowClient\SignalInput;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowClient\StartUpdateOutput;
use Temporal\Interceptor\WorkflowClient\TerminateInput;
use Temporal\Interceptor\WorkflowClient\UpdateInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartOutput;
use Temporal\Interceptor\WorkflowClientCallsInterceptor;
use Temporal\Workflow\WorkflowExecution;

/**
 * @phpstan-type CollectorEntry array{class: string, ids: list<string>, taskQueues: list<string>}
 * @phpstan-type CollectorTypeRow array{class: string, id: string}
 * @phpstan-type CollectorWorker array{taskQueue: string, options: array<string, string>, workflows: list<CollectorTypeRow>, activities: list<CollectorTypeRow>}
 */
class TemporalCollector extends AbstractDataCollector implements WorkflowClientCallsInterceptor
{
    use WorkflowClientCallsInterceptorTrait;

    /** @var list<TemporalClientCall> */
    private array $calls = [];

    public function __construct(
        #[Autowire(lazy: true)]
        private readonly TemporalIntrospectorInterface $introspector,
    )
    {
    }

    public function start(StartInput $input, callable $next): WorkflowExecution
    {
        $call = $this->getStartCall('start', $input, null);

        return $this->trace($call, static fn (): WorkflowExecution => $next($input));
    }

    public function signalWithStart(SignalWithStartInput $input, callable $next): WorkflowExecution
    {
        $call = $this->getStartCall('signalWithStart', $input->workflowStartInput, $input->signalName);

        return $this->trace($call, static fn (): WorkflowExecution => $next($input));
    }

    public function updateWithStart(UpdateWithStartInput $input, callable $next): UpdateWithStartOutput
    {
        $call = $this->getStartCall('updateWithStart', $input->workflowStartInput, $input->updateInput->updateName);

        return $this->trace($call, static fn (): UpdateWithStartOutput => $next($input));
    }

    public function signal(SignalInput $input, callable $next): void
    {
        $call = $this->getExecutionCall('signal', $input->workflowType, $input->workflowExecution, $input->signalName);

        $this->trace($call, static function () use ($next, $input): void {
            $next($input);
        });
    }

    public function query(QueryInput $input, callable $next): ?ValuesInterface
    {
        $call = $this->getExecutionCall('query', $input->workflowType, $input->workflowExecution, $input->queryType);

        return $this->trace($call, static fn (): ?ValuesInterface => $next($input));
    }

    public function update(UpdateInput $input, callable $next): StartUpdateOutput
    {
        $call = $this->getExecutionCall('update', $input->workflowType, $input->workflowExecution, $input->updateName);

        return $this->trace($call, static fn (): StartUpdateOutput => $next($input));
    }

    public function getResult(GetResultInput $input, callable $next): ?ValuesInterface
    {
        $call = $this->getExecutionCall('getResult', $input->workflowType, $input->workflowExecution, null);

        return $this->trace($call, static fn (): ?ValuesInterface => $next($input));
    }

    public function describe(DescribeInput $input, callable $next): WorkflowExecutionDescription
    {
        $call = $this->getExecutionCall('describe', null, $input->workflowExecution, null);

        return $this->trace($call, static fn (): WorkflowExecutionDescription => $next($input));
    }

    public function cancel(CancelInput $input, callable $next): void
    {
        $call = $this->getExecutionCall('cancel', null, $input->workflowExecution, null);

        $this->trace($call, static function () use ($next, $input): void {
            $next($input);
        });
    }

    public function terminate(TerminateInput $input, callable $next): void
    {
        $call = $this->getExecutionCall('terminate', null, $input->workflowExecution, $input->reason);

        $this->trace($call, static function () use ($next, $input): void {
            $next($input);
        });
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $workflowsByQueue = $this->introspector->workflowsByQueue();
        $activitiesByQueue = $this->introspector->activitiesByQueue();

        /** @var array<class-string, list<string>> $workflowIds */
        $workflowIds = [];
        foreach ($workflowsByQueue as $classes) {
            foreach ($classes as $class) {
                if (isset($workflowIds[$class])) {
                    continue;
                }
                $id = $this->introspector->workflowId($class);
                $workflowIds[$class] = $id === null ? [] : [$id];
            }
        }

        /** @var array<class-string, list<string>> $activityIds */
        $activityIds = [];
        foreach ($activitiesByQueue as $classes) {
            foreach ($classes as $class) {
                $activityIds[$class] ??= $this->introspector->activityIds($class);
            }
        }

        /** @var list<CollectorWorker> $workers */
        $workers = [];
        foreach ($this->introspector->workerSummaries() as $summary) {
            $taskQueue = $summary['taskQueue'];
            $workers[] = [
                'taskQueue'  => $taskQueue,
                'options'    => $this->getDisplayedOptions($summary['options']),
                'workflows'  => $this->getTypeRows($workflowsByQueue[$taskQueue] ?? [], $workflowIds),
                'activities' => $this->getTypeRows($activitiesByQueue[$taskQueue] ?? [], $activityIds),
            ];
        }

        $this->data = [
            'calls'      => $this->calls,
            'workers'    => $workers,
            'workflows'  => $this->getEntriesByClass($workflowsByQueue, $workflowIds),
            'activities' => $this->getEntriesByClass($activitiesByQueue, $activityIds),
        ];
    }

    public function reset(): void
    {
        parent::reset();

        $this->calls = [];
    }

    /**
     * @return list<TemporalClientCall>
     */
    public function getCalls(): array
    {
        $calls = $this->getDataEntry('calls');
        $clientCalls = array_filter($calls, static fn (mixed $call): bool => $call instanceof TemporalClientCall);

        return array_values($clientCalls);
    }

    public function getCallsDurationMilliseconds(): float
    {
        $durations = array_map(static fn (TemporalClientCall $call): float => $call->durationMilliseconds, $this->getCalls());

        return array_sum($durations);
    }

    public function getFailedCallCount(): int
    {
        $failedCalls = array_filter($this->getCalls(), static fn (TemporalClientCall $call): bool => $call->error !== null);

        return count($failedCalls);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getWorkers(): array
    {
        return $this->getDataEntry('workers');
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getActivities(): array
    {
        return $this->getDataEntry('activities');
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getWorkflows(): array
    {
        return $this->getDataEntry('workflows');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getDataEntry(string $key): array
    {
        $isCollected = is_array($this->data);
        if (!$isCollected) {
            return [];
        }

        $entry = $this->data[$key] ?? [];

        return is_array($entry) ? $entry : [];
    }

    public function getName(): string
    {
        return 'fluffy_discord.roadrunner.temporal';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function getDisplayedOptions(array $options): array
    {
        return array_map(static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $options);
    }

    private function getStartCall(string $call, StartInput $input, ?string $name): TemporalClientCall
    {
        return new TemporalClientCall(
            call: $call,
            workflowType: $input->workflowType,
            workflowId: $input->workflowId,
            taskQueue: $input->options->taskQueue,
            name: $name,
        );
    }

    private function getExecutionCall(string $call, ?string $workflowType, WorkflowExecution $execution, ?string $name): TemporalClientCall
    {
        return new TemporalClientCall(
            call: $call,
            workflowType: $workflowType,
            workflowId: $execution->getID(),
            runId: $execution->getRunID(),
            name: $name,
        );
    }

    /**
     * @template TResult
     * @param callable(): TResult $invoke
     * @return TResult
     */
    private function trace(TemporalClientCall $call, callable $invoke): mixed
    {
        $startedAt = hrtime(true);

        try {
            $result = $invoke();
        } catch (\Throwable $throwable) {
            $this->record($call, null, $startedAt, $throwable::class . ': ' . $throwable->getMessage());

            throw $throwable;
        }

        $this->record($call, $this->getRunId($result), $startedAt, null);

        return $result;
    }

    private function getRunId(mixed $result): ?string
    {
        if ($result instanceof WorkflowExecution) {
            return $result->getRunID();
        }

        if ($result instanceof UpdateWithStartOutput) {
            return $result->execution->getRunID();
        }

        return null;
    }

    private function record(TemporalClientCall $call, ?string $runId, int|float $startedAt, ?string $error): void
    {
        $elapsedNanoseconds = hrtime(true) - $startedAt;

        $this->calls[] = $call->withOutcome(
            sequence: count($this->calls),
            runId: $runId,
            durationMilliseconds: $elapsedNanoseconds / 1_000_000,
            error: $error,
        );
    }

    /**
     * @param list<class-string>                $classes
     * @param array<class-string, list<string>> $idsByClass
     * @return list<CollectorTypeRow>
     */
    private function getTypeRows(array $classes, array $idsByClass): array
    {
        $rows = [];
        foreach ($classes as $class) {
            $ids = $idsByClass[$class] ?? [];
            if ($ids === []) {
                $rows[] = ['class' => $class, 'id' => ''];
                continue;
            }
            foreach ($ids as $id) {
                $rows[] = ['class' => $class, 'id' => $id];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, list<class-string>> $byQueue
     * @param array<class-string, list<string>> $idsByClass
     * @return array<class-string, CollectorEntry>
     */
    private function getEntriesByClass(array $byQueue, array $idsByClass): array
    {
        $result = [];
        foreach ($byQueue as $taskQueue => $classes) {
            foreach ($classes as $class) {
                $previous = $result[$class]['taskQueues'] ?? [];
                $result[$class] = [
                    'class'      => $class,
                    'ids'        => $idsByClass[$class] ?? [],
                    'taskQueues' => array_values(array_unique([...$previous, $taskQueue])),
                ];
            }
        }

        return $result;
    }
}
