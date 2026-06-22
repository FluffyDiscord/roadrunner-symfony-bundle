<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertTypedSearchAttributesInput;

/**
 * @extends MutableInputEvent<UpsertTypedSearchAttributesInput>
 */
#[Exclude]
final class UpsertTypedSearchAttributesEvent extends MutableInputEvent
{
}
