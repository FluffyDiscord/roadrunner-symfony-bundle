<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Serializer;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;

final class NativeJobSerializer implements JobSerializerInterface
{
    public function name(): string
    {
        return 'native';
    }

    public function serialize(object $message): string
    {
        try {
            return \serialize($message);
        } catch (\Throwable $e) {
            throw new JobSerializationException(\sprintf('Failed to serialize job message "%s": %s', $message::class, $e->getMessage()), 0, $e);
        }
    }

    public function deserialize(string $payload, string $class): object
    {
        $object = @\unserialize($payload, ['allowed_classes' => true]);

        if (!\is_object($object) || !$object instanceof $class) {
            throw new JobSerializationException(\sprintf('Failed to unserialize job payload for "%s".', $class));
        }

        return $object;
    }
}
