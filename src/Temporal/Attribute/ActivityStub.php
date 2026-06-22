<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Attribute;

/**
 * Declares an activity stub on a workflow property. The bundle populates the property with
 * Workflow::newActivityStub() before the workflow runs, so the property stays untyped (the SDK
 * proxy does not implement the activity interface) — add a /** @var Interface *\/ docblock for the IDE.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ActivityStub
{
    /**
     * @param class-string                       $activity     Activity interface, or a single-class activity FQCN
     * @param int|string|\DateInterval|null      $startToClose Seconds, a duration string, or a \DateInterval
     * @param int<0, max>|null                   $retryAttempts null = Temporal default; 0 = unlimited; n = n attempts
     * @param list<class-string<\Throwable>>     $nonRetryable
     */
    public function __construct(
        public string                       $activity,
        public ?string                      $queue = null,
        public int|string|\DateInterval|null $startToClose = null,
        public int|string|\DateInterval|null $scheduleToClose = null,
        public int|string|\DateInterval|null $scheduleToStart = null,
        public int|string|\DateInterval|null $heartbeat = null,
        public ?int                         $retryAttempts = null,
        public ?float                       $retryBackoff = null,
        public int|string|\DateInterval|null $retryInitialInterval = null,
        public int|string|\DateInterval|null $retryMaxInterval = null,
        public array                        $nonRetryable = [],
    )
    {
    }
}
