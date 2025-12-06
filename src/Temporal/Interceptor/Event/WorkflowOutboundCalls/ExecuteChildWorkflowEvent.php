<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteChildWorkflowInput;

#[Exclude]
class ExecuteChildWorkflowEvent
{
    public function __construct(
        private ExecuteChildWorkflowInput $input,
    )
    {
    }

    public function getInput(): ExecuteChildWorkflowInput
    {
        return $this->input;
    }

    public function setInput(ExecuteChildWorkflowInput $input): void
    {
        $this->input = $input;
    }
}
