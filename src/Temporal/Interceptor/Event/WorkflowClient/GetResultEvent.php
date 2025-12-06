<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\GetResultInput;

#[Exclude]
class GetResultEvent
{
    public function __construct(
        private GetResultInput $input,
    )
    {
    }

    public function getInput(): GetResultInput
    {
        return $this->input;
    }

    public function setInput(GetResultInput $input): void
    {
        $this->input = $input;
    }
}