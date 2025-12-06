<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\CancelExternalWorkflowInput;

#[Exclude]
class CancelExternalWorkflowEvent
{
    public function __construct(
        private CancelExternalWorkflowInput $input,
    )
    {
    }

    public function getInput(): CancelExternalWorkflowInput
    {
        return $this->input;
    }

    public function setInput(CancelExternalWorkflowInput $input): void
    {
        $this->input = $input;
    }
}
