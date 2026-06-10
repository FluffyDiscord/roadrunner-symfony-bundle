<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\AbstractWorkflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
#[TaskQueue('default')]
class StubTypedWorkflow extends AbstractWorkflow
{
    #[ActivityStub(GreetingActivity::class, startToClose: 60)]
    private GreetingActivity $greet;

    #[WorkflowMethod(name: 'StubTyped')]
    public function run(): \Generator
    {
        yield 1;

        return null;
    }
}
