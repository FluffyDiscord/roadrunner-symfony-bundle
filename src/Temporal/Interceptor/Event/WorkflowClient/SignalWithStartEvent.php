<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\MutableInputEvent;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;

/**
 * @extends MutableInputEvent<SignalWithStartInput>
 */
#[Exclude]
final class SignalWithStartEvent extends MutableInputEvent
{
}
