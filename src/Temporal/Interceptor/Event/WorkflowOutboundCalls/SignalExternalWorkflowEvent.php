<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowOutboundCalls\SignalExternalWorkflowInput;

/**
 * @extends MutableInputEvent<SignalExternalWorkflowInput>
 */
#[Exclude]
final class SignalExternalWorkflowEvent extends MutableInputEvent
{
}
