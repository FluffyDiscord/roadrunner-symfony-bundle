<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Serializer;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;

/**
 * Strategy for turning a job message object into a transport string and back.
 *
 * The strategy's {@see name()} is recorded in the envelope's x-job-serializer header so the consumer
 * decodes with the same strategy the producer used.
 */
interface JobSerializerInterface
{
    /**
     * Stable identifier stored in the x-job-serializer header (e.g. "native", "symfony").
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * @throws JobSerializationException
     */
    public function serialize(object $message): string;

    /**
     * @param class-string $class
     * @throws JobSerializationException
     */
    public function deserialize(string $payload, string $class): object;
}
