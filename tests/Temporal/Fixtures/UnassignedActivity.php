<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use Temporal\Activity\ActivityInterface;

/**
 * An activity deliberately missing #[TaskQueue] — drives the
 * ActivityNotAssignedException path.
 */
#[ActivityInterface(prefix: 'orphan.')]
class UnassignedActivity
{
    public function run(): void
    {
    }
}
