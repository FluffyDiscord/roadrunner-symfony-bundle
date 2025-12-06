<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;

#[Exclude]
class ExecuteActivityEvent
{
    public function __construct(
        private ExecuteActivityInput $input,
    )
    {
    }

    public function getInput(): ExecuteActivityInput
    {
        return $this->input;
    }

    public function setInput(ExecuteActivityInput $input): void
    {
        $this->input = $input;
    }
}
