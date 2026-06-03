<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobHandlerException;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Listens to JobsRunEvent at priority -100 (after default-priority raw listeners). Detects bundle
 * envelopes by their x-job-class header, rehydrates the message with the strategy named in
 * x-job-serializer, and invokes every #[AsJobHandler] registered for that message class.
 *
 * A non-enveloped task (no x-job-class) is left untouched so raw JobsRunEvent listeners still own it.
 * The listener never acks/nacks: a thrown exception makes the worker nack-with-requeue; returning
 * normally lets the worker ack.
 *
 * @phpstan-type JobHandler array{0: string, 1: string, 2: int}
 * @phpstan-type JobRoutingTable array<class-string, list<JobHandler>>
 */
final class JobRoutingListener
{
    /**
     * @param ServiceLocator<object>                              $locator      Lazy locator of handler services.
     * @param JobRoutingTable                                     $routingTable Compile-time message→handler map.
     * @param array<non-empty-string, JobSerializerInterface>     $serializers  Strategy registry keyed by name().
     */
    public function __construct(
        private readonly ServiceLocator $locator,
        private readonly array $routingTable,
        private readonly array $serializers,
    ) {
    }

    public function onJobsRun(JobsRunEvent $event): void
    {
        $envelope = JobEnvelope::fromTask($event->getPayload(), $event->getHeaders());
        if ($envelope === null) {
            // Not a bundle envelope — leave it for raw JobsRunEvent listeners.
            return;
        }

        $serializer = $this->serializers[$envelope->serializerName] ?? null;
        if ($serializer === null) {
            throw new JobSerializationException(\sprintf(
                'No serializer "%s" available to decode job "%s".',
                $envelope->serializerName,
                $envelope->messageClass,
            ));
        }

        $message = $serializer->deserialize($envelope->payload, $envelope->messageClass);

        foreach ($this->routingTable[$envelope->messageClass] ?? [] as [$serviceId, $method]) {
            try {
                $this->locator->get($serviceId)->$method($message);
            } catch (\Throwable $e) {
                throw new JobHandlerException(\sprintf(
                    'Handler "%s::%s" failed for job "%s": %s',
                    $serviceId,
                    $method,
                    $envelope->messageClass,
                    $e->getMessage(),
                ), 0, $e);
            }
        }
        // No registered handler → no-op; the worker acks the task as a normal success.
    }
}
