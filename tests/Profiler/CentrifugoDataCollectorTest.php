<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Profiler;

use FluffyDiscord\RoadRunnerBundle\Profiler\CentrifugoDataCollector;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CentrifugoDataCollectorTest extends BaseTestCase
{
    private CentrifugoDataCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new CentrifugoDataCollector();
    }

    public function testGetNameReturnsCentrifugo(): void
    {
        self::assertSame('centrifugo', $this->collector->getName());
    }

    public function testHasDataReturnsFalseByDefault(): void
    {
        self::assertFalse($this->collector->hasData());
    }

    public function testDefaultAccessorValues(): void
    {
        self::assertSame('Unknown', $this->collector->getEventType());
        self::assertSame(0.0, $this->collector->getDurationMs());
        self::assertTrue($this->collector->isSuccess());
        self::assertNull($this->collector->getError());
        self::assertSame(0, $this->collector->getStartedAt());
    }

    public function testPopulateStoresDataAndHasDataReturnsTrue(): void
    {
        $this->collector->populate('Publish', 12.34, 1700000000, true, null);

        self::assertTrue($this->collector->hasData());
        self::assertSame('Publish', $this->collector->getEventType());
        self::assertSame(12.34, $this->collector->getDurationMs());
        self::assertSame(1700000000, $this->collector->getStartedAt());
        self::assertTrue($this->collector->isSuccess());
        self::assertNull($this->collector->getError());
    }

    public function testPopulateWithFailure(): void
    {
        $this->collector->populate('Connect', 5.0, 1700000000, false, 'Something went wrong');

        self::assertFalse($this->collector->isSuccess());
        self::assertSame('Something went wrong', $this->collector->getError());
    }

    public function testResetClearsData(): void
    {
        $this->collector->populate('Publish', 12.34, 1700000000, true, null);
        $this->collector->reset();

        self::assertFalse($this->collector->hasData());
        self::assertSame('Unknown', $this->collector->getEventType());
    }

    public function testCollectIsNoOp(): void
    {
        $this->collector->populate('Publish', 12.34, 1700000000, true, null);
        $this->collector->collect(new Request(), new Response());

        self::assertTrue($this->collector->hasData());
        self::assertSame('Publish', $this->collector->getEventType());
    }
}
