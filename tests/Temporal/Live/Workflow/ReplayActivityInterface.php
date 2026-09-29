<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'replay.')]
interface ReplayActivityInterface
{
    #[ActivityMethod]
    public function touch(): string;
}
