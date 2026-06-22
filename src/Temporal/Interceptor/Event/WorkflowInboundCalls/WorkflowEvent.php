<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\WorkflowInput;

/**
 * @extends MutableInputEvent<WorkflowInput>
 */
#[Exclude]
final class WorkflowEvent extends MutableInputEvent
{
}
