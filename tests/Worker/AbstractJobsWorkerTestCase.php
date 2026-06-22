<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Unlike Centrifugo, the Jobs API is interface-driven: ConsumerInterface and ReceivedTaskInterface
 * are plain interfaces, so they can be mocked directly. We drive the loop through a waitTask() seam
 * (so we can hand the worker a queue of mock tasks) and observe ack()/nack() on each task mock plus
 * stop()/error() on the injected goridge worker mock.
 */
class TestableJobsWorker extends JobsWorker
{
    /** @var list<string> */
    public array $loggedErrors = [];
    public int $shutdownRegistrations = 0;
    public ?\Closure $registeredShutdown = null;
    /** @var list<ReceivedTaskInterface|null> */
    public array $taskQueue = [];

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function registerShutdown(callable $handler): void
    {
        ++$this->shutdownRegistrations;
        $this->registeredShutdown = \Closure::fromCallable($handler);
    }

    protected function waitTask(): ?ReceivedTaskInterface
    {
        if ($this->taskQueue === []) {
            return null;
        }

        return array_shift($this->taskQueue);
    }

    public function callHandleShutdown(bool $handlingTask, bool $responded, ?ReceivedTaskInterface $task, ?array $error): void
    {
        $this->handleShutdown($handlingTask, $responded, $task, $error);
    }

    public function callSendThrowableResponse(ReceivedTaskInterface $task, \Throwable $throwable): void
    {
        $this->sendThrowableResponse($task, $throwable);
    }
}

/**
 * A real ReceivedTaskInterface double. ack()/nack()/requeue() are `@method` magic on the interface
 * (declared concretely only on the final-ish ReceivedTask, which needs a live goridge worker to
 * respond()), so they cannot be configured on a PHPUnit interface mock. This spy implements the
 * interface directly and records ack/nack/requeue calls for assertions.
 */
class SpyReceivedTask implements ReceivedTaskInterface
{
    public int $ackCount = 0;
    /** @var list<array{message: string, redelivery: bool}> */
    public array $nackCalls = [];
    /** @var list<string> */
    public array $requeueCalls = [];

    public ?\Throwable $nackThrows = null;

    /**
     * @param array<non-empty-string, array<string>> $headers
     */
    public function __construct(
        private readonly string $id = 'task-id',
        private readonly string $pipeline = 'test-pipeline',
        private readonly string $name = 'job-name',
        private readonly string $queue = 'test-queue',
        private readonly string $payload = 'payload',
        private array $headers = [],
        private bool $completed = false,
    ) {
    }

    public function ack(): void
    {
        ++$this->ackCount;
        $this->completed = true;
    }

    public function nack(string|\Stringable|\Throwable $message, bool $redelivery = false): void
    {
        if ($this->nackThrows !== null) {
            throw $this->nackThrows;
        }
        $this->nackCalls[] = ['message' => (string)$message, 'redelivery' => $redelivery];
        $this->completed = true;
    }

    public function requeue(string|\Stringable|\Throwable $message): void
    {
        $this->requeueCalls[] = (string)$message;
        $this->completed = true;
    }

    public function complete(): void
    {
        $this->ack();
    }

    public function fail(string|\Stringable|\Throwable $error, bool $requeue = false): void
    {
        $this->nack($error, $requeue);
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function isSuccessful(): bool
    {
        return $this->completed && $this->nackCalls === [];
    }

    public function isFails(): bool
    {
        return $this->nackCalls !== [];
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getDriver(): Driver
    {
        return Driver::Memory;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPipeline(): string
    {
        return $this->pipeline;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader(string $name, string|iterable $value): static
    {
        $self = clone $this;
        $self->headers[$name] = is_iterable($value) ? array_values([...$value]) : [$value];
        return $self;
    }

    public function withAddedHeader(string $name, string|iterable $value): static
    {
        $self = clone $this;
        $add = is_iterable($value) ? array_values([...$value]) : [$value];
        $self->headers[$name] = array_merge($self->headers[$name] ?? [], $add);
        return $self;
    }

    public function withoutHeader(string $name): static
    {
        $self = clone $this;
        unset($self->headers[$name]);
        return $self;
    }

    public function withDelay(int $seconds): static
    {
        return clone $this;
    }
}

abstract class AbstractJobsWorkerTestCase extends BaseTestCase
{
    protected RrWorkerInterface&MockObject $rrWorker;
    protected ConsumerInterface&MockObject $consumer;
    protected KernelInterface&MockObject $kernel;
    protected EventDispatcherInterface&MockObject $eventDispatcher;
    protected ServicesResetterInterface&MockObject $servicesResetter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rrWorker = $this->createMock(RrWorkerInterface::class);
        $this->consumer = $this->createMock(ConsumerInterface::class);
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->servicesResetter = $this->createMock(ServicesResetterInterface::class);
    }

    /**
     * @param list<ReceivedTaskInterface|null> $tasks
     */
    protected function makeWorker(array $tasks = [], ?SentryHubInterface $sentryHub = null): TestableJobsWorker
    {
        $worker = new TestableJobsWorker(
            lazyBoot: true,
            kernel: $this->kernel,
            consumer: $this->consumer,
            rrWorker: $this->rrWorker,
            eventDispatcher: $this->eventDispatcher,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: $sentryHub,
        );
        $worker->taskQueue = $tasks;

        return $worker;
    }

    /**
     * A fresh spy task. By default isCompleted() is false (no listener took ownership).
     *
     * @param array<non-empty-string, array<string>> $headers
     */
    protected function makeTask(
        string $name = 'job-name',
        string $payload = 'payload',
        array $headers = [],
        bool $completed = false,
    ): SpyReceivedTask {
        return new SpyReceivedTask(
            name: $name,
            payload: $payload,
            headers: $headers,
            completed: $completed,
        );
    }
}
