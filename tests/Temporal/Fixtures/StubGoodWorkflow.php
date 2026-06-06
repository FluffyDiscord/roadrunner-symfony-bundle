<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\AbstractWorkflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
#[TaskQueue('default')]
class StubGoodWorkflow extends AbstractWorkflow
{
    #[ActivityStub(GreetingActivity::class, startToClose: '5 minutes', retryAttempts: 3)]
    private $greet;

    #[WorkflowMethod(name: 'StubGood')]
    public function run(): \Generator
    {
        return yield $this->greet->greet('world');
    }
}
