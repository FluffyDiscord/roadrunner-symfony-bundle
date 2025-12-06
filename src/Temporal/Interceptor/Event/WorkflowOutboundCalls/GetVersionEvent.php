<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\GetVersionInput;

#[Exclude]
class GetVersionEvent
{
    public function __construct(
        private GetVersionInput $input,
    )
    {
    }

    public function getInput(): GetVersionInput
    {
        return $this->input;
    }

    public function setInput(GetVersionInput $input): void
    {
        $this->input = $input;
    }
}
