<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\SignalInput;

#[Exclude]
class SignalEvent
{
    public function __construct(
        private SignalInput $input,
    )
    {
    }

    public function getInput(): SignalInput
    {
        return $this->input;
    }

    public function setInput(SignalInput $input): void
    {
        $this->input = $input;
    }
}