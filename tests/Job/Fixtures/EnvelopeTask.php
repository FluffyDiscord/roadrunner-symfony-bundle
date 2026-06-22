<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;

/**
 * A self-contained ReceivedTaskInterface double for routing-listener tests. ack()/nack()/requeue()
 * are `@method` magic on the interface and need a live goridge worker on the concrete ReceivedTask,
 * so we implement the interface directly and record completion (mirrors tests/Worker SpyReceivedTask
 * without coupling to that file).
 *
 * @param array<non-empty-string, array<string>> $headers
 */
final class EnvelopeTask implements ReceivedTaskInterface
{
    private bool $completed = false;

    /**
     * @param array<non-empty-string, array<string>> $headers
     */
    public function __construct(
        private readonly string $name,
        private readonly string $payload,
        private array $headers = [],
        private readonly string $id = 'task-id',
        private readonly string $pipeline = 'test-pipeline',
        private readonly string $queue = 'test-queue',
    ) {
    }

    public function ack(): void
    {
        $this->completed = true;
    }

    public function nack(string|\Stringable|\Throwable $message, bool $redelivery = false): void
    {
        $this->completed = true;
    }

    public function requeue(string|\Stringable|\Throwable $message): void
    {
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
        return $this->completed;
    }

    public function isFails(): bool
    {
        return false;
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
