<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\ActivityInbound\ActivityInput;

#[Exclude]
class ActivityEvent
{
    public function __construct(
        private ActivityInput $input,
    )
    {
    }

    public function getInput(): ActivityInput
    {
        return $this->input;
    }

    public function setInput(ActivityInput $input): void
    {
        $this->input = $input;
    }
}