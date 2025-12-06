<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertSearchAttributesInput;

#[Exclude]
class UpsertSearchAttributesEvent
{
    public function __construct(
        private UpsertSearchAttributesInput $input,
    )
    {
    }

    public function getInput(): UpsertSearchAttributesInput
    {
        return $this->input;
    }

    public function setInput(UpsertSearchAttributesInput $input): void
    {
        $this->input = $input;
    }
}
