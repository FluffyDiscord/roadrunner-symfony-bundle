<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\TimerInput;

#[Exclude]
class TimerEvent
{
    public function __construct(
        private TimerInput $input,
    )
    {
    }

    public function getInput(): TimerInput
    {
        return $this->input;
    }

    public function setInput(TimerInput $input): void
    {
        $this->input = $input;
    }
}
