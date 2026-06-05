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

final class JobRoutingListener
{
    public const TRANSPORT_NAME = 'roadrunner';

    /**
     * @param array<non-empty-string, JobSerializerInterface> $serializers
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
            $this->logger?->warning('No handler for job message "{class}"; acking as no-op.', [
                'class' => $envelope->messageClass,
                'exception' => $noHandler,
            ]);
        }
    }
}
