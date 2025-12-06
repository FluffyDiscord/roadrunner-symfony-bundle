<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClientCalls\AwaitEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\AwaitWithTimeoutEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\CancelExternalWorkflowEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\CompleteEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ContinueAsNewEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteChildWorkflowEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteLocalActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\GetVersionEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\PanicEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\SideEffectEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\SignalExternalWorkflowEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\TimerEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\UpsertMemoEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\UpsertSearchAttributesEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\UpsertTypedSearchAttributesEvent;
use React\Promise\PromiseInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitInput;
use Temporal\Interceptor\WorkflowOutboundCalls\AwaitWithTimeoutInput;
use Temporal\Interceptor\WorkflowOutboundCalls\CancelExternalWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ContinueAsNewInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteChildWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteLocalActivityInput;
use Temporal\Interceptor\WorkflowOutboundCalls\GetVersionInput;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCalls\SideEffectInput;
use Temporal\Interceptor\WorkflowOutboundCalls\SignalExternalWorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\TimerInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertMemoInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertSearchAttributesInput;
use Temporal\Interceptor\WorkflowOutboundCalls\UpsertTypedSearchAttributesInput;

class WorkflowOutboundCallsInterceptor implements \Temporal\Interceptor\WorkflowOutboundCallsInterceptor
{
    use WorkflowOutboundCallsInterceptorTrait;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    )
    {
    }

    public function await(AwaitInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new AwaitEvent($input));

        return $next($event->getInput());
    }

    public function awaitWithTimeout(AwaitWithTimeoutInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new AwaitWithTimeoutEvent($input));

        return $next($event->getInput());
    }

    public function cancelExternalWorkflow(CancelExternalWorkflowInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new CancelExternalWorkflowEvent($input));

        return $next($event->getInput());
    }

    public function complete(CompleteInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new CompleteEvent($input));

        return $next($event->getInput());
    }

    public function continueAsNew(ContinueAsNewInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new ContinueAsNewEvent($input));

        return $next($event->getInput());
    }

    public function executeActivity(ExecuteActivityInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new ExecuteActivityEvent($input));

        return $next($event->getInput());
    }

    public function executeChildWorkflow(ExecuteChildWorkflowInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new ExecuteChildWorkflowEvent($input));

        return $next($event->getInput());
    }

    public function executeLocalActivity(ExecuteLocalActivityInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new ExecuteLocalActivityEvent($input));

        return $next($event->getInput());
    }

    public function getVersion(GetVersionInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new GetVersionEvent($input));

        return $next($event->getInput());
    }

    public function panic(PanicInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new PanicEvent($input));

        return $next($event->getInput());
    }

    public function sideEffect(SideEffectInput $input, callable $next): mixed
    {
        $event = $this->eventDispatcher->dispatch(new SideEffectEvent($input));

        return $next($event->getInput());
    }

    public function signalExternalWorkflow(SignalExternalWorkflowInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new SignalExternalWorkflowEvent($input));

        return $next($event->getInput());
    }

    public function timer(TimerInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new TimerEvent($input));

        return $next($event->getInput());
    }

    public function upsertMemo(UpsertMemoInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new UpsertMemoEvent($input));

        return $next($event->getInput());
    }

    public function upsertSearchAttributes(UpsertSearchAttributesInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new UpsertSearchAttributesEvent($input));

        return $next($event->getInput());
    }

    public function upsertTypedSearchAttributes(UpsertTypedSearchAttributesInput $input, callable $next): PromiseInterface
    {
        $event = $this->eventDispatcher->dispatch(new UpsertTypedSearchAttributesEvent($input));

        return $next($event->getInput());
    }
}