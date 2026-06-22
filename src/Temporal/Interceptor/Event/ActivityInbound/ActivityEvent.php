<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\ActivityInbound\ActivityInput;

/**
 * @extends MutableInputEvent<ActivityInput>
 */
#[Exclude]
final class ActivityEvent extends MutableInputEvent
{
}
