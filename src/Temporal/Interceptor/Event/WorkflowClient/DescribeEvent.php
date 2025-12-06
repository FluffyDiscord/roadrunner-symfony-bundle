<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\DescribeInput;

#[Exclude]
class DescribeEvent
{
    public function __construct(
        private DescribeInput $input,
    )
    {
    }

    public function getInput(): DescribeInput
    {
        return $this->input;
    }

    public function setInput(DescribeInput $input): void
    {
        $this->input = $input;
    }
}