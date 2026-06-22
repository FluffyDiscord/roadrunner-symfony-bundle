<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;

/**
 * @extends MutableInputEvent<ExecuteActivityInput>
 */
#[Exclude]
final class ExecuteActivityEvent extends MutableInputEvent
{
}
