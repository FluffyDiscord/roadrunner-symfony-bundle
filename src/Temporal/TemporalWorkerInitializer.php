<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use FluffyDiscord\RoadRunnerBundle\Exception\WorkerNotPristineException;
use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Internal\Declaration\Prototype\ActivityCollection;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;

/**
 * @internal
 */
class TemporalWorkerInitializer
{
    /** @var array<int, class-string> */
    private array $activities = [];

    /** @var array<int, class-string> */
    private array $workflows = [];

    public function __construct(
        private readonly KernelInterface           $kernel,

        #[Autowire(service: 'services_resetter')]
        private readonly ServicesResetterInterface $servicesResetter,

        /** @var TemporalWorkerInterface[] */
        #[AutowireIterator('fluffydiscord.roadrunner.temporal.worker')]
        private readonly iterable                  $workers,
    )
    {
    }

    public function addActivity(string $activity, array $taskQueues): void
    {
        foreach ($taskQueues as $taskQueue) {
            $this->activities[$taskQueue][] = $activity;
        }
    }

    public function addWorkflow(string $workflow, array $taskQueues): void
    {
        foreach ($taskQueues as $taskQueue) {
            $this->workflows[$taskQueue][] = $workflow;
        }
    }

    /**
     * @param WorkerFactoryInterface $workerFactory
     * @return array<int, array{factory: WorkerFactoryInterface, worker: WorkerInterface}>
     */
    public function initialize(WorkerFactoryInterface $workerFactory): array
    {
        $temporalWorkers = [];
        foreach ($this->workers as $worker) {
            $temporalWorker = $worker->create($workerFactory);

            $this->ensureWorkerIsPristine($temporalWorker, $worker);

            /**
             * we are not using the {@see RoadRunnerMicroKernelTrait} modified boot,
             * as this should be enough
             */
            $temporalWorker->registerActivityFinalizer(fn() => $this->servicesResetter->reset());

            foreach ($this->workflows[$temporalWorker->getID()] ?? [] as $workflow) {
                $temporalWorker->registerWorkflowTypes($workflow);
            }

            foreach ($this->activities[$temporalWorker->getID()] ?? [] as $activity) {
                $temporalWorker->registerActivity(
                    $activity,
                    fn(\ReflectionClass $class) => $this->kernel->getContainer()->get($class->getName()),
                );
            }

            $temporalWorkers[] = [
                'factory' => $worker,
                'worker' => $temporalWorker,
            ];
        }

        return $temporalWorkers;
    }

    private function ensureWorkerIsPristine(WorkerInterface $temporalWorker, TemporalWorkerInterface $worker): void
    {
        $workerActivities = $temporalWorker->getActivities();
        if (count($workerActivities) !== 0 || count($temporalWorker->getWorkflows()) !== 0 || ($workerActivities instanceof ActivityCollection && $workerActivities->getFinalizer() !== null)) {
            throw new WorkerNotPristineException(sprintf('Worker "%s" cannot have any activity, workflow or activity finalizer manually set', $worker::class));
        }
    }
}