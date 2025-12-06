<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\WorkflowInput;

#[Exclude]
class WorkflowEvent
{
    public function __construct(
        private WorkflowInput $input,
    )
    {
    }

    public function getInput(): WorkflowInput
    {
        return $this->input;
    }

    public function setInput(WorkflowInput $input): void
    {
        $this->input = $input;
    }
}