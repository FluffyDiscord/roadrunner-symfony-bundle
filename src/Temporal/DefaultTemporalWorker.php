<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Psr\Log\LoggerInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;

class DefaultTemporalWorker implements TemporalWorkerInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    )
    {
    }

    public function create(WorkerFactoryInterface $workerFactory): WorkerInterface
    {
        return $workerFactory->newWorker(
            logger: $this->logger,
        );
    }
}