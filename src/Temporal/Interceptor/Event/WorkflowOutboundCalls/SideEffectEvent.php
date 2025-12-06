<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\SideEffectInput;

#[Exclude]
class SideEffectEvent
{
    public function __construct(
        private SideEffectInput $input,
    )
    {
    }

    public function getInput(): SideEffectInput
    {
        return $this->input;
    }

    public function setInput(SideEffectInput $input): void
    {
        $this->input = $input;
    }
}
