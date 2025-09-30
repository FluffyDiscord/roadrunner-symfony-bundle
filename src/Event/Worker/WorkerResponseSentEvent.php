<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker;

use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Primarily used with {@link RoadRunnerMicroKernelTrait}
 * to call Symfony's {@link ServicesResetter} after response has been sent.
 *
 * Also used as alternative to Symfony's {@link TerminateEvent}
 * since that one is dispatched only for HttpWorker,
 * but this one is dispatched for every single worker type.
 */
class WorkerResponseSentEvent extends Event
{
}