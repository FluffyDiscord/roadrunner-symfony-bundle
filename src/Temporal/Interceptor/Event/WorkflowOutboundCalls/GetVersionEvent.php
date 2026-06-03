<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\GetVersionInput;

/**
 * @extends MutableInputEvent<GetVersionInput>
 */
#[Exclude]
final class GetVersionEvent extends MutableInputEvent
{
}
