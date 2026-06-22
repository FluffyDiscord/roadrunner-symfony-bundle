<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\StartInput;

/**
 * @extends MutableInputEvent<StartInput>
 */
#[Exclude]
final class StartEvent extends MutableInputEvent
{
}
