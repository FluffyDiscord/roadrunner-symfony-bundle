<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow;

use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface CounterWorkflowInterface
{
    #[WorkflowMethod(name: 'CounterWorkflow')]
    public function run(): \Generator;

    #[SignalMethod]
    public function add(int $n): void;

    #[SignalMethod]
    public function finish(): void;

    #[QueryMethod]
    public function getCount(): int;
}
