<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\StartInput;

#[Exclude]
class StartEvent
{
    public function __construct(
        private StartInput $input,
    )
    {
    }

    public function getInput(): StartInput
    {
        return $this->input;
    }

    public function setInput(StartInput $input): void
    {
        $this->input = $input;
    }
}