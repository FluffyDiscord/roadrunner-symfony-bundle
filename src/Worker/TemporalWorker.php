<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\BootFailureReporting;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\WorkerFactory;

class TemporalWorker implements WorkerInterface
{
    use BootFailureReporting;

    public function __construct(
        private readonly KernelInterface                $kernel,
        private readonly EventDispatcherInterface       $eventDispatcher,
        private readonly TemporalWorkerFactoryInterface $temporalWorkerFactory,
        private readonly TemporalWorkerInitializer      $temporalWorkerInitializer,
        private readonly TemporalWorkerRegistry         $temporalWorkerRegistry,
        private readonly HostConnectionInterface        $hostConnection,
        private readonly ?SentryHubInterface            $sentryHubInterface = null,
    )
    {
    }

    public function start(): void
    {
        try {
            $this->kernel->boot();

            $this->eventDispatcher->dispatch(new WorkerBootingEvent());
        } catch (\Throwable $bootThrowable) {
            $this->reportBootFailure($bootThrowable);
        }

        $workerFactory = $this->temporalWorkerFactory->create();

        foreach ($this->temporalWorkerInitializer->initialize($workerFactory) as $entry) {
            $this->temporalWorkerRegistry->add($entry['taskQueue'], $entry['worker']);
        }

        try {
            // WorkerFactoryInterface::run() declares no parameters; only the concrete WorkerFactory accepts the host connection.
            if ($workerFactory instanceof WorkerFactory) {
                $workerFactory->run($this->hostConnection);
            } else {
                $workerFactory->run();
            }
        } catch (\Throwable $throwable) {
            try {
                $this->sentryHubInterface?->captureException($throwable);
                $this->sentryHubInterface?->getClient()?->flush();
            } catch (\Throwable) {
            }

            throw $throwable;
        }
    }

    protected function getBootFailureSentryHub(): ?SentryHubInterface
    {
        return $this->sentryHubInterface;
    }
}
