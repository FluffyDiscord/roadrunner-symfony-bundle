<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

/**
 * A message without #[AsJob], so the dispatcher must fall back to its default queue / no delay.
 */
final class PlainMessage
{
    public function __construct(
        public string $text = '',
    ) {
    }
}
