<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteChildWorkflowInput;

/**
 * @extends MutableInputEvent<ExecuteChildWorkflowInput>
 */
#[Exclude]
final class ExecuteChildWorkflowEvent extends MutableInputEvent
{
}
