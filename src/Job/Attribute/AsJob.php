<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsJob
{
    /**
     * @param non-empty-string|null $queue
     * @param int<0, max>|null      $delay
     * @param int<0, max>|null      $priority
     */
    public function __construct(
        public readonly ?string $queue = null,
        public readonly ?int $delay = null,
        public readonly ?int $priority = null,
    ) {
    }
}
