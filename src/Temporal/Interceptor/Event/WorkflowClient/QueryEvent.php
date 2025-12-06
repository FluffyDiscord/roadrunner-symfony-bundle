<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\QueryInput;

#[Exclude]
class QueryEvent
{
    public function __construct(
        private QueryInput $input,
    )
    {
    }

    public function getInput(): QueryInput
    {
        return $this->input;
    }

    public function setInput(QueryInput $input): void
    {
        $this->input = $input;
    }
}