<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\CancelInput;

#[Exclude]
class CancelEvent
{
    public function __construct(
        private CancelInput $input,
    )
    {
    }

    public function getInput(): CancelInput
    {
        return $this->input;
    }

    public function setInput(CancelInput $input): void
    {
        $this->input = $input;
    }
}