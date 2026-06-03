<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface FailingWorkflowInterface
{
    #[WorkflowMethod(name: 'FailingWorkflow')]
    public function run(): \Generator;
}
