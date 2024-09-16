<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Use this event to additionally preload/cache things you want on worker boot.
 *
 * Be aware, that if you have chosen your worker
 * to "lazy_boot" (in config setting "true"),
 * Symfony kernel might not be fully booted yet!
 */
class WorkerBootingEvent extends Event
{

}