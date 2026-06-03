<?php

namespace FluffyDiscord\RoadRunnerBundle\Job;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use Spiral\RoadRunner\Jobs\JobsInterface;
use Spiral\RoadRunner\Jobs\Options;
use Spiral\RoadRunner\Jobs\Task\PreparedTask;

/**
 * Serializes a plain PHP object into a bundle envelope and pushes it onto an RoadRunner Jobs queue.
 * Routing options resolve as explicit argument > #[AsJob] attribute > dispatcher default.
 */
final class JobDispatcher
{
    /**
     * @param non-empty-string $defaultQueue
     */
    public function __construct(
        private readonly JobsInterface $jobs,
        private readonly JobSerializerInterface $serializer,
        private readonly string $defaultQueue,
    ) {
    }

    /**
     * @param int<0, max>|null $delay    Delay in seconds; null → #[AsJob] default, else none.
     * @param int<0, max>|null $priority RR priority; null → #[AsJob] default, else queue default.
     */
    public function dispatch(object $message, ?string $queue = null, ?int $delay = null, ?int $priority = null): void
    {
        $attribute = $this->readAsJob($message);

        if ($queue === null) {
            $queue = ($attribute !== null ? $attribute->queue : null) ?? $this->defaultQueue;
        }
        if ($queue === '') {
            throw new \InvalidArgumentException('Job queue name must not be empty.');
        }

        if ($delay === null && $attribute !== null) {
            $delay = $attribute->delay;
        }
        if ($priority === null && $attribute !== null) {
            $priority = $attribute->priority;
        }

        $envelope = new JobEnvelope(
            $message::class,
            $this->serializer->name(),
            $this->serializer->serialize($message),
        );

        // PreparedTask is built directly so explicit options override queue defaults.
        $options = new Options($delay ?? Options::DEFAULT_DELAY, $priority ?? Options::DEFAULT_PRIORITY);
        $task = new PreparedTask($message::class, $envelope->payload, $options, $envelope->toHeaders());

        $this->jobs->connect($queue)->dispatch($task);
    }

    private function readAsJob(object $message): ?AsJob
    {
        $attributes = (new \ReflectionClass($message))->getAttributes(AsJob::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
