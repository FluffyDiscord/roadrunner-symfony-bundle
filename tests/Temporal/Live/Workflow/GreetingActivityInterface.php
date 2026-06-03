<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'greeting.')]
interface GreetingActivityInterface
{
    #[ActivityMethod]
    public function greet(string $name): string;
}
