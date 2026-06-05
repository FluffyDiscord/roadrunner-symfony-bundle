<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Serializer;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\SerializerInterface;

final class SymfonyJobSerializer implements JobSerializerInterface
{
    public function __construct(
        private readonly ?SerializerInterface $serializer = null,
    ) {
    }

    public function name(): string
    {
        return 'symfony';
    }

    public function serialize(object $message): string
    {
        $serializer = $this->requireSerializer();

        try {
            return $serializer->serialize($message, 'json');
        } catch (SerializerException $e) {
            throw new JobSerializationException(\sprintf('Failed to serialize job message "%s": %s', $message::class, $e->getMessage()), 0, $e);
        }
    }

    public function deserialize(string $payload, string $class): object
    {
        $serializer = $this->requireSerializer();

        try {
            $object = $serializer->deserialize($payload, $class, 'json');
        } catch (SerializerException $e) {
            throw new JobSerializationException(\sprintf('Failed to rehydrate job message "%s": %s', $class, $e->getMessage()), 0, $e);
        }

        if (!\is_object($object)) {
            throw new JobSerializationException(\sprintf('Deserialized job payload for "%s" is not an object.', $class));
        }

        return $object;
    }

    private function requireSerializer(): SerializerInterface
    {
        if ($this->serializer === null) {
            throw new JobSerializationException('symfony/serializer is not installed; cannot use the "symfony" job serializer.');
        }

        return $this->serializer;
    }
}
