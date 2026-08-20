<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;

/**
 * A boot listener that dies, shared by both worker suites below.
 */
trait FailingBootListener
{
    private function failBootListener(): void
    {
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof WorkerBootingEvent) {
                    throw new \RuntimeException('boot listener exploded');
                }

                return $event;
            })
        ;
    }
}
