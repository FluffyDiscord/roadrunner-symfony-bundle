<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertTypedSearchAttributesInput;

#[Exclude]
class UpsertTypedSearchAttributesEvent
{
    public function __construct(
        private UpsertTypedSearchAttributesInput $input,
    )
    {
    }

    public function getInput(): UpsertTypedSearchAttributesInput
    {
        return $this->input;
    }

    public function setInput(UpsertTypedSearchAttributesInput $input): void
    {
        $this->input = $input;
    }
}
