<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\WorkerFactory;

class DefaultTemporalWorkerFactory implements TemporalWorkerFactoryInterface
{
    public function __construct(
        private readonly RPCConnectionInterface $RPCConnection,
    )
    {
    }

    public function create(): WorkerFactoryInterface
    {
        return WorkerFactory::create(
            rpc: $this->RPCConnection,
        );
    }
}