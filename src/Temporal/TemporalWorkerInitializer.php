<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TemporalTaskQueue;
use JetBrains\PhpStorm\Immutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Worker\WorkerFactoryInterface;

/**
 * TODO: get rid of this class and implement loader in the container compilation step
 * @internal
 */
class TemporalWorkerInitializer
{
    /** @var TemporalWorkerInterface[] */
    #[Immutable(allowedWriteScope: Immutable::PRIVATE_WRITE_SCOPE)]
    public array $workers = [];

    /** @var array<int, class-string> */
    #[Immutable(allowedWriteScope: Immutable::PRIVATE_WRITE_SCOPE)]
    public array $activities = [];

    /** @var array<int, class-string> */
    #[Immutable(allowedWriteScope: Immutable::PRIVATE_WRITE_SCOPE)]
    public array $workflows = [];

    public function __construct(
        private readonly KernelInterface           $kernel,

        #[Autowire(service: 'services_resetter')]
        private readonly ServicesResetterInterface $servicesResetter,
    )
    {
    }

    public function addWorker(TemporalWorkerInterface $worker): void
    {
        $this->workers[$worker::class] = $worker;
    }

    public function addActivity(string $activity): void
    {
        $this->activities[] = $activity;
    }

    public function addWorkflow(string $workflow): void
    {
        $this->workflows[] = $workflow;
    }

    public function initialize(WorkerFactoryInterface $workerFactory): void
    {
        foreach ($this->workers as $worker) {
            $temporalWorker = $worker->create($workerFactory);

            /**
             * we are not using the {@see RoadRunnerMicroKernelTrait} modified boot,
             * as this should be enough
             */
            $temporalWorker->registerActivityFinalizer(fn() => $this->servicesResetter->reset());

            foreach ($this->workflows as $workflow) {
                $reflection = new \ReflectionClass($workflow);
                foreach ($reflection->getAttributes(TemporalTaskQueue::class) as $attribute) {
                    $attributeInstance = $attribute->newInstance();
                    assert($attributeInstance instanceof TemporalTaskQueue);

                    foreach ($attributeInstance->name as $name) {
                        if ($temporalWorker->getID() === $name) {
                            $temporalWorker->registerWorkflowTypes($workflow);
                        }
                    }
                }
            }

            foreach ($this->activities as $activity) {
                $reflection = new \ReflectionClass($activity);
                foreach ($reflection->getAttributes(TemporalTaskQueue::class) as $attribute) {
                    $attributeInstance = $attribute->newInstance();
                    assert($attributeInstance instanceof TemporalTaskQueue);

                    foreach ($attributeInstance->name as $name) {
                        if ($temporalWorker->getID() === $name) {
                            $temporalWorker->registerActivity(
                                $activity,
                                fn(\ReflectionClass $class) => $this->kernel->getContainer()->get($class->getName()),
                            );
                        }
                    }
                }
            }
        }
    }
}