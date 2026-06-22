<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\SideEffectInput;

/**
 * @extends MutableInputEvent<SideEffectInput>
 */
#[Exclude]
final class SideEffectEvent extends MutableInputEvent
{
}
