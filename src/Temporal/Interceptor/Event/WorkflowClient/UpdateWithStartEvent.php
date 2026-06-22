<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;

/**
 * @extends MutableInputEvent<UpdateWithStartInput>
 */
#[Exclude]
final class UpdateWithStartEvent extends MutableInputEvent
{
}
