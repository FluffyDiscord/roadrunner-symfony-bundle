<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
#[TaskQueue('default')]
class GreetingWorkflow
{
    #[WorkflowMethod(name: 'GreetingWorkflow')]
    public function greet(string $name): \Generator
    {
        yield $name;

        return 'Hello, ' . $name;
    }
}
