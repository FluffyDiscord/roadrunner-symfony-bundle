<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker;

use Symfony\Contracts\EventDispatcher\Event;

class WorkerResponseSentEvent extends Event
{
    public function __construct(
        public readonly string $workerType,
    )
    {
    }
}