<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobHandlerException;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\SymfonyJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\Address;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\EnvelopeTask;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmail;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmailHandler;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;

class JobRoutingListenerTest extends BaseTestCase
{
    /**
     * @param array<class-string, list<array{0: string, 1: string, 2: int}>> $table
     * @param array<string, object>                                          $services
     * @param array<non-empty-string, JobSerializerInterface>                $serializers
     */
    private function listener(array $table, array $services, array $serializers): JobRoutingListener
    {
        $locator = new ServiceLocator(array_map(
            static fn (object $svc): \Closure => static fn (): object => $svc,
            $services,
        ));

        return new JobRoutingListener($locator, $table, $serializers);
    }

    private function native(): JobSerializerInterface
    {
        return new NativeJobSerializer();
    }

    private function symfony(): JobSerializerInterface
    {
        return new SymfonyJobSerializer(new Serializer([new PropertyNormalizer()], [new JsonEncoder()]));
    }

    /**
     * @param array<non-empty-string, array<string>> $extraHeaders
     */
    private function task(string $payload, array $extraHeaders): ReceivedTaskInterface
    {
        return new EnvelopeTask(
            name: SendWelcomeEmail::class,
            payload: $payload,
            headers: $extraHeaders,
        );
    }

    public function testEnvelopedTaskInvokesHandlerWithRehydratedMessage(): void
    {
        $serializer = $this->native();
        $handler = new SendWelcomeEmailHandler();
        $message = new SendWelcomeEmail(email: 'a@b.test', attempts: 4, tags: ['x'], address: new Address('Brno', '60200'));

        $envelope = new JobEnvelope(SendWelcomeEmail::class, $serializer->name(), $serializer->serialize($message));
        $task = $this->task($envelope->payload, $envelope->toHeaders());

        $listener = $this->listener(
            [SendWelcomeEmail::class => [[SendWelcomeEmailHandler::class, '__invoke', 0]]],
            [SendWelcomeEmailHandler::class => $handler],
            ['native' => $serializer],
        );

        $listener->onJobsRun(new JobsRunEvent($task));

        self::assertCount(1, $handler->received);
        self::assertSame('a@b.test', $handler->received[0]->email);
        self::assertSame(4, $handler->received[0]->getAttempts());
        self::assertInstanceOf(Address::class, $handler->received[0]->address);
        self::assertSame('60200', $handler->received[0]->address->getZip());
        self::assertFalse($task->isCompleted(), 'listener must not ack/nack the task');
    }

    public function testNonEnvelopedTaskIsIgnored(): void
    {
        $handler = new SendWelcomeEmailHandler();
        $task = $this->task('raw-payload', []);

        $listener = $this->listener(
            [SendWelcomeEmail::class => [[SendWelcomeEmailHandler::class, '__invoke', 0]]],
            [SendWelcomeEmailHandler::class => $handler],
            ['native' => new NativeJobSerializer()],
        );

        $listener->onJobsRun(new JobsRunEvent($task));

        self::assertSame([], $handler->received);
        self::assertFalse($task->isCompleted());
    }

    public function testUnknownSerializerThrows(): void
    {
        $task = $this->task('payload', [
            JobEnvelope::HEADER_CLASS => [SendWelcomeEmail::class],
            JobEnvelope::HEADER_SERIALIZER => ['nonexistent'],
        ]);

        $listener = $this->listener([], [], []);

        $this->expectException(JobSerializationException::class);
        $listener->onJobsRun(new JobsRunEvent($task));
    }

    public function testValidEnvelopeWithoutHandlerIsNoOp(): void
    {
        $serializer = $this->native();
        $envelope = new JobEnvelope(SendWelcomeEmail::class, 'native', $serializer->serialize(new SendWelcomeEmail()));
        $task = $this->task($envelope->payload, $envelope->toHeaders());

        $listener = $this->listener([], [], ['native' => $serializer]);

        $listener->onJobsRun(new JobsRunEvent($task));

        self::assertFalse($task->isCompleted());
        $this->addToAssertionCount(1);
    }

    public function testHandlerFailureIsWrappedInJobHandlerExceptionWithOriginalAsPrevious(): void
    {
        $serializer = $this->native();
        $original = new \RuntimeException('boom from handler');
        $handler = new class($original) {
            public function __construct(private readonly \Throwable $toThrow)
            {
            }

            public function __invoke(SendWelcomeEmail $message): void
            {
                throw $this->toThrow;
            }
        };

        $envelope = new JobEnvelope(SendWelcomeEmail::class, $serializer->name(), $serializer->serialize(new SendWelcomeEmail()));
        $task = $this->task($envelope->payload, $envelope->toHeaders());

        $listener = $this->listener(
            [SendWelcomeEmail::class => [['throwing_handler', '__invoke', 0]]],
            ['throwing_handler' => $handler],
            ['native' => $serializer],
        );

        try {
            $listener->onJobsRun(new JobsRunEvent($task));
            self::fail('Expected JobHandlerException.');
        } catch (JobHandlerException $e) {
            self::assertSame($original, $e->getPrevious());
            self::assertStringContainsString('throwing_handler::__invoke', $e->getMessage());
            self::assertStringContainsString(SendWelcomeEmail::class, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: 'native'|'symfony'}>
     */
    public static function serializerProvider(): iterable
    {
        yield 'native' => ['native'];
        yield 'symfony' => ['symfony'];
    }

    /**
     * @param 'native'|'symfony' $strategy
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serializerProvider')]
    public function testRoundTripThroughBothStrategies(string $strategy): void
    {
        $serializer = $strategy === 'native' ? $this->native() : $this->symfony();
        $handler = new SendWelcomeEmailHandler();
        $message = new SendWelcomeEmail(email: 'round@trip.test', attempts: 7, tags: ['a', 'b']);

        $envelope = new JobEnvelope(SendWelcomeEmail::class, $serializer->name(), $serializer->serialize($message));
        $task = $this->task($envelope->payload, $envelope->toHeaders());

        $listener = $this->listener(
            [SendWelcomeEmail::class => [[SendWelcomeEmailHandler::class, '__invoke', 0]]],
            [SendWelcomeEmailHandler::class => $handler],
            ['native' => new NativeJobSerializer(), 'symfony' => $this->symfony()],
        );

        $listener->onJobsRun(new JobsRunEvent($task));

        self::assertCount(1, $handler->received);
        self::assertSame('round@trip.test', $handler->received[0]->email);
        self::assertSame(7, $handler->received[0]->getAttempts());
        self::assertSame(['a', 'b'], $handler->received[0]->tags);
    }
}
