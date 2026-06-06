<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
#[TaskQueue('default')]
class StubNoHydrateWorkflow
{
    #[ActivityStub(GreetingActivity::class, startToClose: 60)]
    private $greet;

    #[WorkflowMethod(name: 'StubNoHydrate')]
    public function run(): \Generator
    {
        yield 1;

        return null;
    }
}
