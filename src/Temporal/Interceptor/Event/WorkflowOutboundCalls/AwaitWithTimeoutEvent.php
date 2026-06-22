<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitWithTimeoutInput;

/**
 * @extends MutableInputEvent<AwaitWithTimeoutInput>
 */
#[Exclude]
final class AwaitWithTimeoutEvent extends MutableInputEvent
{
}
