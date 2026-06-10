<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Attribute;

use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

/**
 * Default start options for a workflow, read by WorkflowLauncher::of(). Place it on the workflow
 * interface (or class); the fluent builder overrides any field per call (e.g. the dynamic id).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class WorkflowDefaults
{
    /**
     * @param int|string|\DateInterval|null $executionTimeout Seconds, a duration string, or a \DateInterval
     * @param int<0, max>|null              $retryAttempts    0 = unlimited
     */
    public function __construct(
        public ?string                      $queue = null,
        public ?IdReusePolicy               $reusePolicy = null,
        public ?WorkflowIdConflictPolicy    $conflictPolicy = null,
        public int|string|\DateInterval|null $executionTimeout = null,
        public ?int                         $retryAttempts = null,
        public ?float                       $retryBackoff = null,
    )
    {
    }
}
