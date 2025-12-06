<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\QueryEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\SignalEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\UpdateEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\WorkflowEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Temporal\Interceptor\Trait\WorkflowInboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowInbound\QueryInput;
use Temporal\Interceptor\WorkflowInbound\SignalInput;
use Temporal\Interceptor\WorkflowInbound\UpdateInput;
use Temporal\Interceptor\WorkflowInbound\WorkflowInput;

class WorkflowInboundCallsInterceptor implements \Temporal\Interceptor\WorkflowInboundCallsInterceptor
{
    use WorkflowInboundCallsInterceptorTrait;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    )
    {
    }

    public function execute(WorkflowInput $input, callable $next): void
    {
        $event = $this->eventDispatcher->dispatch(new WorkflowEvent($input));

        $next($event->getInput());
    }

    public function handleQuery(QueryInput $input, callable $next): mixed
    {
        $event = $this->eventDispatcher->dispatch(new QueryEvent($input));

        return $next($event->getInput());
    }

    public function handleSignal(SignalInput $input, callable $next): void
    {
        $event = $this->eventDispatcher->dispatch(new SignalEvent($input));

        $next($event->getInput());
    }

    public function handleUpdate(UpdateInput $input, callable $next): mixed
    {
        $event = $this->eventDispatcher->dispatch(new UpdateEvent($input, false));

        return $next($event->getInput());
    }

    public function validateUpdate(UpdateInput $input, callable $next): void
    {
        $event = $this->eventDispatcher->dispatch(new UpdateEvent($input, true));

        $next($event->getInput());
    }
}