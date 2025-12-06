<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\TerminateInput;

#[Exclude]
class TerminateEvent
{
    public function __construct(
        private TerminateInput $input,
    )
    {
    }

    public function getInput(): TerminateInput
    {
        return $this->input;
    }

    public function setInput(TerminateInput $input): void
    {
        $this->input = $input;
    }
}