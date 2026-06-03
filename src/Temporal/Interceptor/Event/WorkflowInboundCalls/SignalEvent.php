<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\SignalInput;

/**
 * @extends MutableInputEvent<SignalInput>
 */
#[Exclude]
final class SignalEvent extends MutableInputEvent
{
}
