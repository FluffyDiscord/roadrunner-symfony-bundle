<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\ActivityStubReader;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\PredefinedActivityStub;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubReaderChild;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubViaSubclass;

/** UT-003 — the hierarchy walk surfaces stubs from the class, an inherited PRIVATE parent property, and a trait. */
class ActivityStubReaderTest extends BaseTestCase
{
    public function testSurfacesClassParentPrivateAndTraitStubs(): void
    {
        $pairs = ActivityStubReader::pairs(new \ReflectionClass(StubReaderChild::class));

        $names = array_map(static fn (array $pair): string => $pair[0]->getName(), $pairs);
        sort($names);

        self::assertSame(['fromBase', 'fromChild', 'fromTrait'], $names, 'inherited private (fromBase) must not be skipped');

        foreach ($pairs as [, $stub]) {
            self::assertInstanceOf(ActivityStub::class, $stub);
            self::assertSame(GreetingActivity::class, $stub->activity);
        }
    }

    public function testSubclassAttributeIsMatchedByInstanceof(): void
    {
        $pairs = ActivityStubReader::pairs(new \ReflectionClass(StubViaSubclass::class));

        self::assertCount(1, $pairs);
        [$property, $stub] = $pairs[0];

        self::assertSame('greet', $property->getName());
        self::assertInstanceOf(PredefinedActivityStub::class, $stub);
        self::assertSame(GreetingActivity::class, $stub->activity);
        self::assertSame('preset', $stub->queue, 'predefined subclass presets are read by the hydrator/guard');
        self::assertSame(42, $stub->startToClose);
        self::assertSame(7, $stub->retryAttempts);
    }
}
