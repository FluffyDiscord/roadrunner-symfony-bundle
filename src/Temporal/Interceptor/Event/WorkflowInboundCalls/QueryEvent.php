<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\QueryInput;

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