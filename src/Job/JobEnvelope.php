<?php

namespace FluffyDiscord\RoadRunnerBundle\Job;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;

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
     * @param array<non-empty-string, array<string>> $headers
     * @throws JobSerializationException
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
