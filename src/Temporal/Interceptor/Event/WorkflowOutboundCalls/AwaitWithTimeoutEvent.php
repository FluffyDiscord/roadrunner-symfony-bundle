<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitWithTimeoutInput;

#[Exclude]
class AwaitWithTimeoutEvent
{
    public function __construct(
        private AwaitWithTimeoutInput $input,
    )
    {
    }

    public function getInput(): AwaitWithTimeoutInput
    {
        return $this->input;
    }

    public function setInput(AwaitWithTimeoutInput $input): void
    {
        $this->input = $input;
    }
}
