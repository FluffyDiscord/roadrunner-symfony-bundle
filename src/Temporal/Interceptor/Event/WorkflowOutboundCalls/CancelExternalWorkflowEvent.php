<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\CancelExternalWorkflowInput;

/**
 * @extends MutableInputEvent<CancelExternalWorkflowInput>
 */
#[Exclude]
final class CancelExternalWorkflowEvent extends MutableInputEvent
{
}
