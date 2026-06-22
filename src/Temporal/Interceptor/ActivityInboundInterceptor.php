<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Trait\ActivityInboundInterceptorTrait;

class ActivityInboundInterceptor implements \Temporal\Interceptor\ActivityInboundInterceptor
{
    use ActivityInboundInterceptorTrait;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    )
    {
    }

    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        $event = $this->eventDispatcher->dispatch(new ActivityEvent($input));

        return $next($event->getInput());
    }
}