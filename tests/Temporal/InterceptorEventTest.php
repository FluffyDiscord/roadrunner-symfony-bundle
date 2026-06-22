<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\UpdateEvent as InboundUpdateEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowInboundCalls\WorkflowEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\WorkflowInbound\UpdateInput;
use Temporal\Interceptor\WorkflowInbound\WorkflowInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;
use Temporal\Workflow\WorkflowInfo;

/**
 * TC-14 — interceptor event objects round-trip their input and expose extra flags.
 */
class InterceptorEventTest extends BaseTestCase
{
    public function testActivityEventRoundTrip(): void
    {
        $input = new ActivityInput(EncodedValues::empty(), Header::empty());
        $event = new ActivityEvent($input);

        self::assertSame($input, $event->getInput());

        $replacement = new ActivityInput(EncodedValues::empty(), Header::empty());
        $event->setInput($replacement);

        self::assertSame($replacement, $event->getInput());
    }

    public function testExecuteActivityEventRoundTrip(): void
    {
        $input = new ExecuteActivityInput('greeting.greet', [], null, null);
        $event = new ExecuteActivityEvent($input);

        self::assertSame($input, $event->getInput());
    }

    public function testWorkflowEventRoundTrip(): void
    {
        $input = new WorkflowInput(new WorkflowInfo(), EncodedValues::empty(), Header::empty(), false);
        $event = new WorkflowEvent($input);

        self::assertSame($input, $event->getInput());
    }

    public function testInboundUpdateEventCarriesValidationFlag(): void
    {
        $input = new UpdateInput(
            'updateName',
            'updateId',
            new WorkflowInfo(),
            EncodedValues::empty(),
            Header::empty(),
            false,
        );

        self::assertTrue((new InboundUpdateEvent($input, true))->isValidation());
        self::assertFalse((new InboundUpdateEvent($input, false))->isValidation());
    }
}
