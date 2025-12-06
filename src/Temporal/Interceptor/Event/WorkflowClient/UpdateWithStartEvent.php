<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;

#[Exclude]
class UpdateWithStartEvent
{
    public function __construct(
        private UpdateWithStartInput $input,
    )
    {
    }

    public function getInput(): UpdateWithStartInput
    {
        return $this->input;
    }

    public function setInput(UpdateWithStartInput $input): void
    {
        $this->input = $input;
    }
}