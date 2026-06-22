<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

/**
 * A job message carrying #[AsJob] defaults plus public, private, list and nested-object state.
 */
#[AsJob(queue: 'emails', delay: 5, priority: 2)]
final class SendWelcomeEmail
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $email = '',
        private int $attempts = 0,
        public array $tags = [],
        public ?Address $address = null,
    ) {
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
