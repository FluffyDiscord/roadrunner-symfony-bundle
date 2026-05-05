<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Event\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\RoadRunner\Environment\Mode;

class WorkerEventTest extends BaseTestCase
{
    public function testWorkerResponseSentEventPreservesWorkerType(): void
    {
        $event = new WorkerResponseSentEvent(Mode::MODE_HTTP);

        self::assertSame(Mode::MODE_HTTP, $event->workerType);
    }

    public function testWorkerResponseSentEventWithCentrifugeMode(): void
    {
        $event = new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE);

        self::assertSame(Mode::MODE_CENTRIFUGE, $event->workerType);
    }

    public function testWorkerBootingEventIsInstantiable(): void
    {
        $event = new WorkerBootingEvent();

        self::assertInstanceOf(WorkerBootingEvent::class, $event);
    }

    public function testWorkerRequestReceivedEventIsInstantiable(): void
    {
        $event = new WorkerRequestReceivedEvent();

        self::assertInstanceOf(WorkerRequestReceivedEvent::class, $event);
    }
}
