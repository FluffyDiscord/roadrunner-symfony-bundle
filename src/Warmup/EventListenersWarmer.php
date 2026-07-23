<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * getListeners() forces instantiation of every lazily-registered listener service.
 * See docs/specs/worker-warmup.md §3.
 */
readonly class EventListenersWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private ?EventDispatcherInterface $eventDispatcher = null,
    )
    {
    }

    public function warmup(): void
    {
        $this->eventDispatcher?->getListeners();
    }
}
