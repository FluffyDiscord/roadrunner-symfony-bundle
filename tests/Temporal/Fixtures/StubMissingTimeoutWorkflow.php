<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\AbstractWorkflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
#[TaskQueue('default')]
class StubMissingTimeoutWorkflow extends AbstractWorkflow
{
    #[ActivityStub(GreetingActivity::class)]
    private $greet;

    #[WorkflowMethod(name: 'StubMissingTimeout')]
    public function run(): \Generator
    {
        yield 1;

        return null;
    }
}
