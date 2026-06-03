<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClientCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitInput;

/**
 * @extends MutableInputEvent<AwaitInput>
 */
#[Exclude]
final class AwaitEvent extends MutableInputEvent
{
}
