<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\GetResultInput;

/**
 * @extends MutableInputEvent<GetResultInput>
 */
#[Exclude]
final class GetResultEvent extends MutableInputEvent
{
}
