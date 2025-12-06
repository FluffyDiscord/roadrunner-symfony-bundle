<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\UpdateInput;

#[Exclude]
class UpdateEvent
{
    public function __construct(
        private UpdateInput $input,
    )
    {
    }

    public function getInput(): UpdateInput
    {
        return $this->input;
    }

    public function setInput(UpdateInput $input): void
    {
        $this->input = $input;
    }
}