<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\UpdateInput;

#[Exclude]
class UpdateEvent
{
    public function __construct(
        private UpdateInput   $input,
        private readonly bool $validation
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

    public function isValidation(): bool
    {
        return $this->validation;
    }
}