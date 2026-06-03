<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJobHandler;

/**
 * Invokable handler whose message type is inferred from the __invoke first parameter.
 */
#[AsJobHandler]
final class SendWelcomeEmailHandler
{
    /** @var list<SendWelcomeEmail> */
    public array $received = [];

    public function __invoke(SendWelcomeEmail $message): void
    {
        $this->received[] = $message;
    }
}
