<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertMemoInput;

#[Exclude]
class UpsertMemoEvent
{
    public function __construct(
        private UpsertMemoInput $input,
    )
    {
    }

    public function getInput(): UpsertMemoInput
    {
        return $this->input;
    }

    public function setInput(UpsertMemoInput $input): void
    {
        $this->input = $input;
    }
}
