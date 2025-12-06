<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;

#[Exclude]
class CompleteEvent
{
    public function __construct(
        private CompleteInput $input,
    )
    {
    }

    public function getInput(): CompleteInput
    {
        return $this->input;
    }

    public function setInput(CompleteInput $input): void
    {
        $this->input = $input;
    }
}
