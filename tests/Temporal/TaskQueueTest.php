<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Temporal\Worker\WorkerFactoryInterface;

/**
 * TC-02 — the #[TaskQueue] attribute.
 */
class TaskQueueTest extends BaseTestCase
{
    public function testDefaultTaskQueueIsTheTemporalDefault(): void
    {
        $attribute = new TaskQueue();

        self::assertSame(WorkerFactoryInterface::DEFAULT_TASK_QUEUE, $attribute->taskQueue);
    }

    public function testCustomTaskQueue(): void
    {
        $attribute = new TaskQueue('billing');

        self::assertSame('billing', $attribute->taskQueue);
    }

    public function testAttributeTargetsClassesAndIsRepeatable(): void
    {
        $reflection = new \ReflectionClass(TaskQueue::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        self::assertCount(1, $attributes);

        $flags = $attributes[0]->newInstance()->flags;

        self::assertSame(\Attribute::TARGET_CLASS, $flags & \Attribute::TARGET_CLASS);
        self::assertSame(\Attribute::IS_REPEATABLE, $flags & \Attribute::IS_REPEATABLE);
    }

    public function testRepeatedAttributeYieldsMultipleInstances(): void
    {
        $reflection = new \ReflectionClass(DummyDoubleQueued::class);
        $attributes = $reflection->getAttributes(TaskQueue::class);

        self::assertCount(2, $attributes);

        $queues = array_map(static fn (\ReflectionAttribute $a) => $a->newInstance()->taskQueue, $attributes);

        self::assertSame(['a', 'b'], $queues);
    }
}

#[TaskQueue('a')]
#[TaskQueue('b')]
class DummyDoubleQueued
{
}
