<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\ContinueAsNewInput;

/**
 * @extends MutableInputEvent<ContinueAsNewInput>
 */
#[Exclude]
final class ContinueAsNewEvent extends MutableInputEvent
{
}
