<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Attribute;

/**
 * Marks a plain PHP object as a dispatchable job message and supplies its default routing options.
 *
 * Placed on the message class itself (not a service). Read at runtime by the JobDispatcher via
 * reflection; explicit dispatch() arguments override these defaults.
 *
 * @see \FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsJob
{
    /**
     * @param non-empty-string|null $queue    RR queue/pipeline name (must already exist in .rr.yaml).
     *                                         Null → the dispatcher's default queue.
     * @param int<0, max>|null      $delay    Delay in seconds before the task becomes available. Null → no delay.
     * @param int<0, max>|null      $priority RR task priority. Null → queue default.
     */
    public function __construct(
        public readonly ?string $queue = null,
        public readonly ?int $delay = null,
        public readonly ?int $priority = null,
    ) {
    }
}
