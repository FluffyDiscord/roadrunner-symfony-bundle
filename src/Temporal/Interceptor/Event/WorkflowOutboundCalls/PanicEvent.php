<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;

#[Exclude]
class PanicEvent
{
    public function __construct(
        private PanicInput $input,
    )
    {
    }

    public function getInput(): PanicInput
    {
        return $this->input;
    }

    public function setInput(PanicInput $input): void
    {
        $this->input = $input;
    }
}
