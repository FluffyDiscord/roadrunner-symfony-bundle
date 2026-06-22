<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\SignalInput;

/**
 * @extends MutableInputEvent<SignalInput>
 */
#[Exclude]
final class SignalEvent extends MutableInputEvent
{
}
