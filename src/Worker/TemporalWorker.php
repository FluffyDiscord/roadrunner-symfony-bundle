<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Worker\Transport\HostConnectionInterface;

class TemporalWorker implements WorkerInterface
{
    public function __construct(
        private readonly KernelInterface                $kernel,
        private readonly EventDispatcherInterface       $eventDispatcher,
        private readonly TemporalWorkerFactoryInterface $temporalWorkerFactory,
        private readonly TemporalWorkerInitializer      $temporalWorkerInitializer,
        private readonly HostConnectionInterface        $hostConnection,
        private readonly ?SentryHubInterface            $sentryHubInterface = null,
    )
    {
    }

    public function start(): void
    {
        $this->kernel->boot();

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        $workerFactory = $this->temporalWorkerFactory->create();

        $this->temporalWorkerInitializer->initialize($workerFactory);

        $workerFactory->run($this->hostConnection);
    }
}
