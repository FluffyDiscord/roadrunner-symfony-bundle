<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

readonly class GrpcFrame
{
    /**
     * @param array<string, list<string>> $metadata
     */
    public function __construct(
        public string $serviceName,
        public string $methodName,
        public array  $metadata,
    )
    {
    }
}
