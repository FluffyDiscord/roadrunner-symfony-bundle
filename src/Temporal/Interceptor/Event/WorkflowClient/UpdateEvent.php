<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\UpdateInput;

/**
 * @extends MutableInputEvent<UpdateInput>
 */
#[Exclude]
final class UpdateEvent extends MutableInputEvent
{
}
