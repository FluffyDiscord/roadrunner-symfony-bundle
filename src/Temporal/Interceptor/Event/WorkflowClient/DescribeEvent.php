<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\DescribeInput;

/**
 * @extends MutableInputEvent<DescribeInput>
 */
#[Exclude]
final class DescribeEvent extends MutableInputEvent
{
}
