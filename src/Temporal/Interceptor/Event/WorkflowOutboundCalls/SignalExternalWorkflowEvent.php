<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\SignalExternalWorkflowInput;

#[Exclude]
class SignalExternalWorkflowEvent
{
    public function __construct(
        private SignalExternalWorkflowInput $input,
    )
    {
    }

    public function getInput(): SignalExternalWorkflowInput
    {
        return $this->input;
    }

    public function setInput(SignalExternalWorkflowInput $input): void
    {
        $this->input = $input;
    }
}
