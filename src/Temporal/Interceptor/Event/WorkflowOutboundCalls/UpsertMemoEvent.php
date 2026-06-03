<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertMemoInput;

/**
 * @extends MutableInputEvent<UpsertMemoInput>
 */
#[Exclude]
final class UpsertMemoEvent extends MutableInputEvent
{
}
