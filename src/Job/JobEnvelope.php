<?php

namespace FluffyDiscord\RoadRunnerBundle\Job;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;

/**
 * Wire contract for an enveloped job task: a serialized payload plus the x-job-class and
 * x-job-serializer task headers. A task missing the x-job-class header is left for raw
 * JobsRunEvent listeners.
 */
final class JobEnvelope
{
    public const HEADER_CLASS = 'x-job-class';
    public const HEADER_SERIALIZER = 'x-job-serializer';

    /**
     * @param class-string     $messageClass
     * @param non-empty-string $serializerName
     */
    public function __construct(
        public readonly string $messageClass,
        public readonly string $serializerName,
        public readonly string $payload,
    ) {
    }

    /**
     * RoadRunner task headers (each value is a single-element list, per the header type contract).
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    public function toHeaders(): array
    {
        return [
            self::HEADER_CLASS => [$this->messageClass],
            self::HEADER_SERIALIZER => [$this->serializerName],
        ];
    }

    /**
     * Reconstructs an envelope from a consumed task's payload + headers.
     *
     * @param array<non-empty-string, array<string>> $headers
     * @return self|null null when the task carries no x-job-class header — i.e. it is NOT a bundle
     *                   envelope and must be left untouched for raw JobsRunEvent listeners.
     * @throws JobSerializationException when the task is enveloped but its message class is unloadable.
     */
    public static function fromTask(string $payload, array $headers): ?self
    {
        $class = $headers[self::HEADER_CLASS][0] ?? null;
        $serializerName = $headers[self::HEADER_SERIALIZER][0] ?? null;

        if (!\is_string($class) || $class === '' || !\is_string($serializerName) || $serializerName === '') {
            return null;
        }

        if (!\class_exists($class)) {
            throw new JobSerializationException(\sprintf('Job message class "%s" does not exist.', $class));
        }

        return new self($class, $serializerName, $payload);
    }
}
