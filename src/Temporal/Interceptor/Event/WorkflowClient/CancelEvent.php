<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\CancelInput;

/**
 * @extends MutableInputEvent<CancelInput>
 */
#[Exclude]
final class CancelEvent extends MutableInputEvent
{
}
