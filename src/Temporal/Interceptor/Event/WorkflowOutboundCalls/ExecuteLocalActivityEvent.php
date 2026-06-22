<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteLocalActivityInput;

/**
 * @extends MutableInputEvent<ExecuteLocalActivityInput>
 */
#[Exclude]
final class ExecuteLocalActivityEvent extends MutableInputEvent
{
}
