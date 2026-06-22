<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\CancelEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\DescribeEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\GetResultEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\QueryEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\SignalEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\SignalWithStartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\StartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\TerminateEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\UpdateEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\UpdateWithStartEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Temporal\Client\Workflow\WorkflowExecutionDescription;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Interceptor\Trait\WorkflowClientCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowClient\CancelInput;
use Temporal\Interceptor\WorkflowClient\DescribeInput;
use Temporal\Interceptor\WorkflowClient\GetResultInput;
use Temporal\Interceptor\WorkflowClient\QueryInput;
use Temporal\Interceptor\WorkflowClient\SignalInput;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowClient\StartUpdateOutput;
use Temporal\Interceptor\WorkflowClient\TerminateInput;
use Temporal\Interceptor\WorkflowClient\UpdateInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartOutput;
use Temporal\Workflow\WorkflowExecution;

class WorkflowClientCallsInterceptor implements \Temporal\Interceptor\WorkflowClientCallsInterceptor
{
    use WorkflowClientCallsInterceptorTrait;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    )
    {
    }

    public function start(StartInput $input, callable $next): WorkflowExecution
    {
        $event = $this->eventDispatcher->dispatch(new StartEvent($input));

        return $next($event->getInput());
    }

    public function signal(SignalInput $input, callable $next): void
    {
        $event = $this->eventDispatcher->dispatch(new SignalEvent($input));

        $next($event->getInput());
    }

    public function cancel(CancelInput $input, callable $next): void
    {
        $event = $this->eventDispatcher->dispatch(new CancelEvent($input));

        $next($event->getInput());
    }

    public function describe(DescribeInput $input, callable $next): WorkflowExecutionDescription
    {
        $event = $this->eventDispatcher->dispatch(new DescribeEvent($input));

        return $next($event->getInput());
    }

    public function getResult(GetResultInput $input, callable $next): ?ValuesInterface
    {
        $event = $this->eventDispatcher->dispatch(new GetResultEvent($input));

        return $next($event->getInput());
    }

    public function query(QueryInput $input, callable $next): ?ValuesInterface
    {
        $event = $this->eventDispatcher->dispatch(new QueryEvent($input));

        return $next($event->getInput());
    }

    public function signalWithStart(SignalWithStartInput $input, callable $next): WorkflowExecution
    {
        $event = $this->eventDispatcher->dispatch(new SignalWithStartEvent($input));

        return $next($event->getInput());
    }

    public function terminate(TerminateInput $input, callable $next): void
    {
        $event = $this->eventDispatcher->dispatch(new TerminateEvent($input));

        $next($event->getInput());
    }

    public function update(UpdateInput $input, callable $next): StartUpdateOutput
    {
        $event = $this->eventDispatcher->dispatch(new UpdateEvent($input));

        return $next($event->getInput());
    }

    public function updateWithStart(UpdateWithStartInput $input, callable $next): UpdateWithStartOutput
    {
        $event = $this->eventDispatcher->dispatch(new UpdateWithStartEvent($input));

        return $next($event->getInput());
    }
}