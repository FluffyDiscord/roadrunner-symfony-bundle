<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'crashing.')]
interface CrashingActivityInterface
{
    #[ActivityMethod]
    public function crash(): string;
}
