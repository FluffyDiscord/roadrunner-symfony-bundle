<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker\Centrifugo;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Can be used as replacement for Symfony\Component\HttpKernel\KernelEvents::TERMINATE
 * @deprecated Use {@link WorkerResponseSentEvent} instead.
 */
class AfterRespondEvent extends Event
{
}