<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Serializer;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;

interface JobSerializerInterface
{
    /**
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
