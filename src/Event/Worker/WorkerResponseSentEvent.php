<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker;

use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Used as alternative to Symfony's {@link TerminateEvent}
 * since that one is dispatched only for HttpWorker,
 * but this one is dispatched for every single worker type.
 */
class WorkerResponseSentEvent extends Event
{
    public function __construct(
        public readonly string $workerType,
    )
    {
    }
}