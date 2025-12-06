<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;

#[Exclude]
class SignalWithStartEvent
{
    public function __construct(
        private SignalWithStartInput $input,
    )
    {
    }

    public function getInput(): SignalWithStartInput
    {
        return $this->input;
    }

    public function setInput(SignalWithStartInput $input): void
    {
        $this->input = $input;
    }
}