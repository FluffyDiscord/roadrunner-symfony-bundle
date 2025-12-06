<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteLocalActivityInput;

#[Exclude]
class ExecuteLocalActivityEvent
{
    public function __construct(
        private ExecuteLocalActivityInput $input,
    )
    {
    }

    public function getInput(): ExecuteLocalActivityInput
    {
        return $this->input;
    }

    public function setInput(ExecuteLocalActivityInput $input): void
    {
        $this->input = $input;
    }
}
