<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;

/**
 * @extends MutableInputEvent<CompleteInput>
 */
#[Exclude]
final class CompleteEvent extends MutableInputEvent
{
}
