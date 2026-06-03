<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\DataConverter\DataConverterInterface;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\WorkerFactory;

class DefaultTemporalWorkerFactory implements TemporalWorkerFactoryInterface
{
    public function __construct(
        private readonly RPCConnectionInterface $RPCConnection,
        private readonly DataConverterInterface $dataConverter,
        private readonly ServiceCredentials     $serviceCredentials,
    )
    {
    }

    public function create(): WorkerFactoryInterface
    {
        return WorkerFactory::create(
            $this->dataConverter,
            $this->RPCConnection,
            $this->serviceCredentials,
        );
    }
}