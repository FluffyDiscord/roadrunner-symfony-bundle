<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Debug;

readonly class GrpcSecurityFacts
{
    public function __construct(
        public bool    $enabled,
        public ?string $tokenHandlerId,
        public ?string $metadataKey,
        public ?bool   $required,
    )
    {
    }
}
