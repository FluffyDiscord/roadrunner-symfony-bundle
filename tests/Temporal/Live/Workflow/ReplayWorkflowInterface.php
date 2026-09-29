<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ReplayWorkflowInterface
{
    #[WorkflowMethod(name: 'ReplayWorkflow')]
    public function run(): \Generator;

    #[SignalMethod]
    public function proceed(): void;
}
