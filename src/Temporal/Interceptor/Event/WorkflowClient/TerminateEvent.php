<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\TerminateInput;

/**
 * @extends MutableInputEvent<TerminateInput>
 */
#[Exclude]
final class TerminateEvent extends MutableInputEvent
{
}
