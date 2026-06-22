<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'greeting.')]
#[TaskQueue('default')]
class GreetingActivity
{
    #[ActivityMethod]
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
