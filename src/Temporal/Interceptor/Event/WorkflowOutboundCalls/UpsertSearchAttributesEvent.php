<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertSearchAttributesInput;

/**
 * @extends MutableInputEvent<UpsertSearchAttributesInput>
 */
#[Exclude]
final class UpsertSearchAttributesEvent extends MutableInputEvent
{
}
