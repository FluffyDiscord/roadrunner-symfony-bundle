<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use FluffyDiscord\RoadRunnerBundle\Exception\DuplicateTemporalWorkerException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;

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
     * @param iterable<TemporalWorkerInterface> $workers
     */
    public function __construct(
        private readonly KernelInterface               $kernel,

        #[Autowire(service: 'services_resetter')]
        private readonly ServicesResetterInterface     $servicesResetter,

        #[AutowireIterator('fluffydiscord.roadrunner.temporal.worker')]
        private readonly iterable                      $workers,

        private readonly ExceptionInterceptorInterface $exceptionInterceptor,
        private readonly PipelineProvider              $pipelineProvider,
        private readonly ?LoggerInterface              $logger = null,
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
     * @return list<array{class: class-string, taskQueue: string}>
     */
    public function getWorkerSummaries(): array
    {
        $summaries = [];
        foreach ($this->chooseWorkers(false) as $taskQueue => $config) {
            $summaries[] = ['class' => $config::class, 'taskQueue' => $taskQueue];
        }

        return $summaries;
    }

    /**
     * @return list<array{config: TemporalWorkerInterface, worker: WorkerInterface, taskQueue: string}>
     */
    public function initialize(WorkerFactoryInterface $workerFactory): array
    {
        $temporalWorkers = [];

        foreach ($this->chooseWorkers(true) as $taskQueue => $config) {
            $worker = $workerFactory->newWorker(
                $taskQueue,
                $config->getWorkerOptions(),
                $this->exceptionInterceptor,
                $this->pipelineProvider,
                $this->logger,
            );

            $worker->registerActivityFinalizer(fn() => $this->servicesResetter->reset());

            foreach ($this->workflows[$taskQueue] ?? [] as $workflow) {
                $worker->registerWorkflowTypes($workflow);
            }

            foreach ($this->activities[$taskQueue] ?? [] as $activity) {
                $worker->registerActivity(
                    $activity,
                    fn(\ReflectionClass $class) => $this->kernel->getContainer()->get($class->getName()),
                );
            }

            $temporalWorkers[] = [
                'config'    => $config,
                'worker'    => $worker,
                'taskQueue' => $taskQueue,
            ];
        }

        return $temporalWorkers;
    }

    /**
     * @return array<string, TemporalWorkerInterface>
     */
    private function chooseWorkers(bool $throwOnConflict): array
    {
        /** @var array<string, TemporalWorkerInterface> $chosen */
        $chosen = [];

        foreach ($this->workers as $config) {
            $taskQueue = $config->getTaskQueue();

            if (!isset($chosen[$taskQueue])) {
                $chosen[$taskQueue] = $config;
                continue;
            }

            $existing = $chosen[$taskQueue];
            $newIsCustom = !$config instanceof DefaultTemporalWorker;
            $existingIsCustom = !$existing instanceof DefaultTemporalWorker;

            if ($newIsCustom && $existingIsCustom) {
                if ($throwOnConflict) {
                    throw new DuplicateTemporalWorkerException(sprintf(
                        'Task queue "%s" is served by more than one worker ("%s" and "%s"), but a task queue maps to exactly one worker. '
                        . 'Register many workflows/activities on a single worker instead, or move them to separate task queues. '
                        . 'See https://docs.temporal.io/workers#task-queue.',
                        $taskQueue,
                        $existing::class,
                        $config::class,
                    ));
                }
                continue;
            }

            if ($newIsCustom) {
                $chosen[$taskQueue] = $config;
            }
        }

        return $chosen;
    }
}
