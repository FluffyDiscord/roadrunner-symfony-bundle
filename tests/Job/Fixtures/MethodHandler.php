<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJobHandler;

/**
 * Handler using an explicit method and explicit message class (no inference).
 */
final class MethodHandler
{
    /** @var list<PlainMessage> */
    public array $received = [];

    #[AsJobHandler(message: PlainMessage::class, priority: 10)]
    public function handle(PlainMessage $message): void
    {
        $this->received[] = $message;
    }
}
