<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClientCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitInput;

#[Exclude]
class AwaitEvent
{
    public function __construct(
        private AwaitInput $input,
    )
    {
    }

    public function getInput(): AwaitInput
    {
        return $this->input;
    }

    public function setInput(AwaitInput $input): void
    {
        $this->input = $input;
    }
}
