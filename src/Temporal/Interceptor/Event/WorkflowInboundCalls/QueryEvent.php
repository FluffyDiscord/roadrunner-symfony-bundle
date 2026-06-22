<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowInbound\QueryInput;

/**
 * @extends MutableInputEvent<QueryInput>
 */
#[Exclude]
final class QueryEvent extends MutableInputEvent
{
}
