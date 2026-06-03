<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'failing.')]
interface FailingActivityInterface
{
    #[ActivityMethod]
    public function boom(): string;
}
