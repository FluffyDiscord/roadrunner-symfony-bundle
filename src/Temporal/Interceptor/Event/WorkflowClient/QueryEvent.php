<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\QueryInput;

/**
 * @extends MutableInputEvent<QueryInput>
 */
#[Exclude]
final class QueryEvent extends MutableInputEvent
{
}
