<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Serializer;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;

/**
 * Selected automatically when the extension is loaded; falls back to native otherwise.
 */
final class IgbinaryJobSerializer implements JobSerializerInterface
{
    public function name(): string
    {
        return 'igbinary';
    }

    public function serialize(object $message): string
    {
        $this->requireExtension();

        try {
            $payload = \igbinary_serialize($message);
        } catch (\Throwable $e) {
            throw new JobSerializationException(\sprintf('Failed to serialize job message "%s": %s', $message::class, $e->getMessage()), 0, $e);
        }

        if ($payload === null) {
            throw new JobSerializationException(\sprintf('Failed to serialize job message "%s".', $message::class));
        }

        return $payload;
    }

    public function deserialize(string $payload, string $class): object
    {
        $this->requireExtension();

        try {
            $object = \igbinary_unserialize($payload);
        } catch (\Throwable $e) {
            throw new JobSerializationException(\sprintf('Failed to unserialize job payload for "%s": %s', $class, $e->getMessage()), 0, $e);
        }

        if (!\is_object($object) || !$object instanceof $class) {
            throw new JobSerializationException(\sprintf('Failed to unserialize job payload for "%s".', $class));
        }

        return $object;
    }

    private function requireExtension(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            throw new JobSerializationException('The "igbinary" PHP extension is not installed; cannot use the "igbinary" job serializer.');
        }
    }
}
