<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\ActivityInboundInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\UpdateEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\WorkflowEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowInboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use React\Promise\PromiseInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\WorkflowInbound\UpdateInput;
use Temporal\Interceptor\WorkflowInbound\WorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;
use Temporal\Workflow\WorkflowInfo;

use function React\Promise\resolve;

/**
 * TC-10 / TC-12 / TC-13 — interceptors dispatch the matching event and forward the
 * (possibly mutated) input to $next.
 */
class InterceptorTest extends BaseTestCase
{
    public function testActivityInboundDispatchesEventAndForwardsInput(): void
    {
        $dispatcher = new EventDispatcher();

        $observed = null;
        $dispatcher->addListener(ActivityEvent::class, function (ActivityEvent $event) use (&$observed): void {
            $observed = $event;
        });

        $interceptor = new ActivityInboundInterceptor($dispatcher);
        $input = new ActivityInput(EncodedValues::empty(), Header::empty());

        $passed = null;
        $result = $interceptor->handleActivityInbound($input, function (ActivityInput $next) use (&$passed) {
            $passed = $next;

            return 'activity-result';
        });

        self::assertInstanceOf(ActivityEvent::class, $observed);
        self::assertSame($input, $passed);
        self::assertSame('activity-result', $result);
    }

    public function testActivityInboundForwardsListenerMutatedInput(): void
    {
        $dispatcher = new EventDispatcher();

        $swapped = new ActivityInput(EncodedValues::empty(), Header::empty());
        $dispatcher->addListener(ActivityEvent::class, function (ActivityEvent $event) use ($swapped): void {
            $event->setInput($swapped);
        });

        $interceptor = new ActivityInboundInterceptor($dispatcher);

        $passed = null;
        $interceptor->handleActivityInbound(
            new ActivityInput(EncodedValues::empty(), Header::empty()),
            function (ActivityInput $next) use (&$passed) {
                $passed = $next;

                return null;
            },
        );

        self::assertSame($swapped, $passed);
    }

    public function testWorkflowInboundUpdateAndValidateCarryTheValidationFlag(): void
    {
        $dispatcher = new EventDispatcher();

        $events = [];
        $dispatcher->addListener(UpdateEvent::class, function (UpdateEvent $event) use (&$events): void {
            $events[] = $event->isValidation();
        });

        $interceptor = new WorkflowInboundCallsInterceptor($dispatcher);
        $input = new UpdateInput('u', 'id', new WorkflowInfo(), EncodedValues::empty(), Header::empty(), false);

        $interceptor->handleUpdate($input, static fn () => null);
        $interceptor->validateUpdate($input, static fn () => null);

        self::assertSame([false, true], $events);
    }

    public function testWorkflowInboundExecuteDispatchesWorkflowEvent(): void
    {
        $dispatcher = new EventDispatcher();

        $observed = false;
        $dispatcher->addListener(WorkflowEvent::class, function () use (&$observed): void {
            $observed = true;
        });

        $interceptor = new WorkflowInboundCallsInterceptor($dispatcher);
        $input = new WorkflowInput(new WorkflowInfo(), EncodedValues::empty(), Header::empty(), false);

        $passed = null;
        $interceptor->execute($input, function (WorkflowInput $next) use (&$passed): void {
            $passed = $next;
        });

        self::assertTrue($observed);
        self::assertSame($input, $passed);
    }

    public function testWorkflowOutboundExecuteActivityDispatchesEventAndForwardsInput(): void
    {
        $dispatcher = new EventDispatcher();

        $observed = false;
        $dispatcher->addListener(ExecuteActivityEvent::class, function () use (&$observed): void {
            $observed = true;
        });

        $interceptor = new WorkflowOutboundCallsInterceptor($dispatcher);
        $input = new ExecuteActivityInput('greeting.greet', [], null, null);

        $passed = null;
        $result = $interceptor->executeActivity($input, function (ExecuteActivityInput $next) use (&$passed): PromiseInterface {
            $passed = $next;

            return resolve('ok');
        });

        self::assertTrue($observed);
        self::assertSame($input, $passed);
        self::assertInstanceOf(PromiseInterface::class, $result);
    }
}
