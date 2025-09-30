<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Be aware, that if you have chosen your worker
 * to "lazy_boot" (in config setting "true"),
 * Symfony kernel is not booted on your first request!
 */
class WorkerRequestReceivedEvent extends Event
{
}