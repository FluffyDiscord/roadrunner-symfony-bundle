<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface CrashingWorkflowInterface
{
    #[WorkflowMethod(name: 'CrashingWorkflow')]
    public function run(): \Generator;
}
