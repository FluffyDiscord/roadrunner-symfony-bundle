<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Listens to JobsRunEvent at priority -100 (after default-priority raw listeners). Detects bundle
 * envelopes by their x-job-class header, rehydrates the message with the strategy named in
 * x-job-serializer, and dispatches it into the Symfony Messenger bus so #[AsMessageHandler] handlers
 * run it. The raw ReceivedTaskInterface is passed to handlers as a second argument.
 *
 * A non-enveloped task (no x-job-class) is left untouched so raw JobsRunEvent listeners still own it.
 * The listener never acks/nacks: a thrown exception makes the worker nack-with-requeue; returning
 * normally lets the worker ack. A valid envelope no handler matches is acked as a no-op.
 */
final class JobRoutingListener
{
    /**
     * Transport name stamped on every RoadRunner-consumed message. Scope a handler to RR jobs with
     * #[AsMessageHandler(fromTransport: JobRoutingListener::TRANSPORT_NAME)]; a different fromTransport
     * value will not match an RR job.
     */
    public const TRANSPORT_NAME = 'roadrunner';

    /**
     * @param array<non-empty-string, JobSerializerInterface> $serializers Strategy registry keyed by name().
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly array $serializers,
        private readonly ?LoggerInterface $logger = null,
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

        try {
            $this->bus->dispatch($message, [
                new ReceivedStamp(self::TRANSPORT_NAME),
                new HandlerArgumentsStamp([$event->getTask()]),
            ]);
        } catch (NoHandlerForMessageException $noHandler) {
            // A valid envelope nobody handles is acked as a no-op, never nack-looped.
            $this->logger?->warning('No handler for job message "{class}"; acking as no-op.', [
                'class' => $envelope->messageClass,
                'exception' => $noHandler,
            ]);
        }
    }
}
