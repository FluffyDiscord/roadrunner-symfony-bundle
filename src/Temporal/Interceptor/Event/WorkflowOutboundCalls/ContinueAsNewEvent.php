<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ContinueAsNewInput;

#[Exclude]
class ContinueAsNewEvent
{
    public function __construct(
        private ContinueAsNewInput $input,
    )
    {
    }

    public function getInput(): ContinueAsNewInput
    {
        return $this->input;
    }

    public function setInput(ContinueAsNewInput $input): void
    {
        $this->input = $input;
    }
}
