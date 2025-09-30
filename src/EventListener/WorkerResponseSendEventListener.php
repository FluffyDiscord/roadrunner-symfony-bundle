<?php

namespace FluffyDiscord\RoadRunnerBundle\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;

class WorkerResponseSendEventListener
{
    public function __construct(
        private readonly ServicesResetter $serviceResetter,
        private readonly bool             $isDebug,
    )
    {
    }

    public function __invoke(WorkerResponseSentEvent $event): void
    {
        if (!$this->isDebug) {
            $this->serviceResetter->reset();
        }
    }
}