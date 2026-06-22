<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\UpdateInput;

/**
 * @extends MutableInputEvent<UpdateInput>
 */
#[Exclude]
final class UpdateEvent extends MutableInputEvent
{
    public function __construct(
        UpdateInput           $input,
        private readonly bool $validation,
    )
    {
        parent::__construct($input);
    }

    public function isValidation(): bool
    {
        return $this->validation;
    }
}
