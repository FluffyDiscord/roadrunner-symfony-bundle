<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Standard Symfony Messenger handler; the Jobs message bus routes #[AsJob] messages to it.
 */
#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    /** @var list<SendWelcomeEmail> */
    public array $received = [];

    public function __invoke(SendWelcomeEmail $message): void
    {
        $this->received[] = $message;
    }
}
