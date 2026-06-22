<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\IgbinaryJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\SymfonyJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\Address;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\EnvelopeTask;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmail;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmailHandler;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;

class JobRoutingListenerTest extends BaseTestCase
{
    private function native(): JobSerializerInterface
    {
        return new NativeJobSerializer();
    }

    private function symfony(): JobSerializerInterface
    {
        return new SymfonyJobSerializer(new Serializer([new PropertyNormalizer()], [new JsonEncoder()]));
    }

    /**
     * @return array<non-empty-string, JobSerializerInterface>
     */
    private function serializers(): array
    {
        return [
            'native' => $this->native(),
            'igbinary' => new IgbinaryJobSerializer(),
            'symfony' => $this->symfony(),
        ];
    }

    /**
     * @param array<class-string, list<HandlerDescriptor>> $handlers
     */
    private function bus(array $handlers): MessageBusInterface
    {
        return new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator($handlers)),
        ]);
    }

    /**
     * @param array<non-empty-string, array<string>> $headers
     */
    private function task(string $payload, array $headers): ReceivedTaskInterface
    {
        return new EnvelopeTask(name: SendWelcomeEmail::class, payload: $payload, headers: $headers);
    }

    private function enveloped(SendWelcomeEmail $message, JobSerializerInterface $serializer): ReceivedTaskInterface
    {
        $envelope = new JobEnvelope(SendWelcomeEmail::class, $serializer->name(), $serializer->serialize($message));

        return $this->task($envelope->payload, $envelope->toHeaders());
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
    #[DataProvider('serializerProvider')]
    public function testEnvelopedTaskIsDispatchedToHandler(string $strategy): void
    {
        $serializer = $strategy === 'native' ? $this->native() : $this->symfony();
        $handler = new SendWelcomeEmailHandler();
        $message = new SendWelcomeEmail(email: 'a@b.test', attempts: 4, tags: ['x'], address: new Address('Brno', '60200'));

        $task = $this->enveloped($message, $serializer);
        $bus = $this->bus([SendWelcomeEmail::class => [new HandlerDescriptor($handler)]]);

        (new JobRoutingListener($bus, $this->serializers()))->onJobsRun(new JobsRunEvent($task));

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
        $bus = $this->bus([SendWelcomeEmail::class => [new HandlerDescriptor($handler)]]);

        (new JobRoutingListener($bus, $this->serializers()))->onJobsRun(new JobsRunEvent($task));

        self::assertSame([], $handler->received);
        self::assertFalse($task->isCompleted());
    }

    public function testNoHandlerIsAckedAsNoOpAndWarned(): void
    {
        $task = $this->enveloped(new SendWelcomeEmail(), $this->native());
        $bus = $this->bus([]); // empty HandlersLocator → NoHandlerForMessageException

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        (new JobRoutingListener($bus, $this->serializers(), $logger))->onJobsRun(new JobsRunEvent($task));

        self::assertCount(1, $logger->warnings);
        self::assertFalse($task->isCompleted(), 'no-handler must not nack the task');

        // logger null → still no throw
        (new JobRoutingListener($bus, $this->serializers()))->onJobsRun(new JobsRunEvent($task));
        $this->addToAssertionCount(1);
    }

    public function testHandlerFailurePropagatesAsHandlerFailedException(): void
    {
        $original = new \RuntimeException('boom from handler');
        $handler = static function (SendWelcomeEmail $message) use ($original): void {
            throw $original;
        };

        $task = $this->enveloped(new SendWelcomeEmail(), $this->native());
        $bus = $this->bus([SendWelcomeEmail::class => [new HandlerDescriptor($handler)]]);

        try {
            (new JobRoutingListener($bus, $this->serializers()))->onJobsRun(new JobsRunEvent($task));
            self::fail('Expected HandlerFailedException.');
        } catch (HandlerFailedException $e) {
            self::assertSame($original, $e->getPrevious());
            self::assertFalse($task->isCompleted(), 'listener must not ack a failed task');
        }
    }

    public function testUnknownSerializerThrows(): void
    {
        $task = $this->task('payload', [
            JobEnvelope::HEADER_CLASS => [SendWelcomeEmail::class],
            JobEnvelope::HEADER_SERIALIZER => ['nonexistent'],
        ]);

        $this->expectException(JobSerializationException::class);
        (new JobRoutingListener($this->bus([]), $this->serializers()))->onJobsRun(new JobsRunEvent($task));
    }

    public function testRegistryKeysMatchSerializerNames(): void
    {
        foreach ($this->serializers() as $key => $serializer) {
            self::assertSame($serializer->name(), $key, 'registry key must equal serializer name()');
        }
    }

    public function testHandlerReceivesRawTaskAndFromTransportScoping(): void
    {
        $received = [];
        $handler = static function (SendWelcomeEmail $message, ReceivedTaskInterface $task) use (&$received): void {
            $received[] = $task;
        };

        // Matching from_transport → handler runs and gets the task.
        $task = $this->enveloped(new SendWelcomeEmail(), $this->native());
        $matching = $this->bus([
            SendWelcomeEmail::class => [new HandlerDescriptor($handler, ['from_transport' => JobRoutingListener::TRANSPORT_NAME])],
        ]);
        (new JobRoutingListener($matching, $this->serializers()))->onJobsRun(new JobsRunEvent($task));

        self::assertCount(1, $received);
        self::assertSame($task, $received[0], 'handler must receive the current ReceivedTaskInterface');

        // Mismatching from_transport → no handler matches → no-op (no throw, task not completed).
        $received = [];
        $other = $this->bus([
            SendWelcomeEmail::class => [new HandlerDescriptor($handler, ['from_transport' => 'something-else'])],
        ]);
        $task2 = $this->enveloped(new SendWelcomeEmail(), $this->native());
        (new JobRoutingListener($other, $this->serializers()))->onJobsRun(new JobsRunEvent($task2));

        self::assertSame([], $received, 'a mismatched from_transport handler must not run');
        self::assertFalse($task2->isCompleted());
    }

    public function testReceivedStampPreventsReSendToTransport(): void
    {
        $handler = new SendWelcomeEmailHandler();

        $spySender = new class implements SenderInterface {
            public int $sent = 0;

            public function send(Envelope $envelope): Envelope
            {
                ++$this->sent;

                return $envelope;
            }
        };

        $sendersLocator = new class ($spySender) implements SendersLocatorInterface {
            public function __construct(private readonly SenderInterface $sender)
            {
            }

            public function getSenders(Envelope $envelope): iterable
            {
                // Would route every message to the spy sender — unless a ReceivedStamp is present.
                if ($envelope->last(ReceivedStamp::class) !== null) {
                    return [];
                }

                yield 'spy' => $this->sender;
            }
        };

        $bus = new MessageBus([
            new SendMessageMiddleware($sendersLocator),
            new HandleMessageMiddleware(new HandlersLocator([SendWelcomeEmail::class => [new HandlerDescriptor($handler)]])),
        ]);

        $task = $this->enveloped(new SendWelcomeEmail(email: 'stamp@test'), $this->native());
        (new JobRoutingListener($bus, $this->serializers()))->onJobsRun(new JobsRunEvent($task));

        self::assertSame(0, $spySender->sent, 'ReceivedStamp must prevent re-sending to a transport');
        self::assertCount(1, $handler->received, 'the message must be handled locally instead');
    }
}
