<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\PlainMessage;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmail;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\Jobs\JobsInterface;
use Spiral\RoadRunner\Jobs\QueueInterface;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Spiral\RoadRunner\Jobs\Task\QueuedTaskInterface;

/**
 * Dispatcher tests: queue/delay/priority resolution and the built task. See
 * docs/specs/jobs-message-bus.md §N-2 TC-05..TC-07.
 */
#[AllowMockObjectsWithoutExpectations]
class JobDispatcherTest extends BaseTestCase
{
    /** @var list<array{queue: string, task: PreparedTaskInterface}> */
    private array $dispatched = [];

    private function serializer(): JobSerializerInterface
    {
        return new NativeJobSerializer();
    }

    private function makeJobs(): JobsInterface
    {
        $this->dispatched = [];

        $jobs = $this->createMock(JobsInterface::class);
        $jobs->method('connect')->willReturnCallback(function (string $queue): QueueInterface {
            $stored = &$this->dispatched;

            $queueMock = $this->createMock(QueueInterface::class);
            $queueMock->method('dispatch')->willReturnCallback(
                function (PreparedTaskInterface $task) use (&$stored, $queue): QueuedTaskInterface {
                    $stored[] = ['queue' => $queue, 'task' => $task];

                    return $this->createMock(QueuedTaskInterface::class);
                },
            );

            return $queueMock;
        });

        return $jobs;
    }

    private function dispatcher(string $defaultQueue = 'default'): JobDispatcher
    {
        return new JobDispatcher($this->makeJobs(), $this->serializer(), $defaultQueue);
    }

    // TC-05
    public function testUsesAttributeQueueByDefault(): void
    {
        $this->dispatcher()->dispatch(new SendWelcomeEmail(email: 'x@y.z'));

        self::assertCount(1, $this->dispatched);
        self::assertSame('emails', $this->dispatched[0]['queue']);
    }

    public function testExplicitQueueOverridesAttribute(): void
    {
        $this->dispatcher()->dispatch(new SendWelcomeEmail(email: 'x@y.z'), queue: 'priority');

        self::assertSame('priority', $this->dispatched[0]['queue']);
    }

    public function testFallsBackToDefaultQueueWithoutAttribute(): void
    {
        $this->dispatcher('catch-all')->dispatch(new PlainMessage('hi'));

        self::assertSame('catch-all', $this->dispatched[0]['queue']);
    }

    public function testEmptyExplicitQueueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dispatcher()->dispatch(new PlainMessage('hi'), queue: '');
    }

    // TC-06
    public function testAttributeDelayAndPriorityApplied(): void
    {
        $this->dispatcher()->dispatch(new SendWelcomeEmail(email: 'x@y.z'));

        $task = $this->dispatched[0]['task'];
        self::assertSame(5, $task->getOptions()->getDelay());
        self::assertSame(2, $task->getOptions()->getPriority());
    }

    public function testExplicitZeroDelayOverridesAttribute(): void
    {
        $this->dispatcher()->dispatch(new SendWelcomeEmail(email: 'x@y.z'), delay: 0, priority: 0);

        $task = $this->dispatched[0]['task'];
        self::assertSame(0, $task->getOptions()->getDelay());
        self::assertSame(0, $task->getOptions()->getPriority());
    }

    public function testNoDelayOrPriorityWithoutAttribute(): void
    {
        $this->dispatcher()->dispatch(new PlainMessage('hi'));

        $task = $this->dispatched[0]['task'];
        self::assertSame(0, $task->getOptions()->getDelay());
        self::assertSame(0, $task->getOptions()->getPriority());
    }

    // TC-07
    public function testTaskCarriesEnvelopeHeadersAndDecodablePayload(): void
    {
        $serializer = $this->serializer();
        $message = new SendWelcomeEmail(email: 'x@y.z', attempts: 9, tags: ['t']);

        $this->dispatcher()->dispatch($message);

        $task = $this->dispatched[0]['task'];

        self::assertSame(SendWelcomeEmail::class, $task->getName());
        self::assertSame([SendWelcomeEmail::class], $task->getHeaders()[JobEnvelope::HEADER_CLASS]);
        self::assertSame(['native'], $task->getHeaders()[JobEnvelope::HEADER_SERIALIZER]);

        $restored = $serializer->deserialize($task->getPayload(), SendWelcomeEmail::class);
        self::assertInstanceOf(SendWelcomeEmail::class, $restored);
        self::assertSame('x@y.z', $restored->email);
        self::assertSame(9, $restored->getAttempts());
    }
}
