<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Transport;

use Temporal\Worker\Transport\CommandBatch;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Workflow;

readonly class WorkflowContextClearingHostConnection implements HostConnectionInterface
{
    public function __construct(
        private HostConnectionInterface $hostConnection,
    )
    {
    }

    public function waitBatch(): ?CommandBatch
    {
        return $this->hostConnection->waitBatch();
    }

    public function send(string $frame): void
    {
        Workflow::setCurrentContext(null);

        $this->hostConnection->send($frame);
    }

    public function error(\Throwable $error): void
    {
        Workflow::setCurrentContext(null);

        $this->hostConnection->error($error);
    }
}
