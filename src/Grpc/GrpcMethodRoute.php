<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use Spiral\RoadRunner\GRPC\Method;
use Symfony\Component\Security\Http\Attribute\IsGranted;

readonly class GrpcMethodRoute
{
    /**
     * @param list<IsGranted> $accessAttributes
     */
    public function __construct(
        public Method $method,
        public array  $accessAttributes,
    )
    {
    }

    public function hasAccessAttributes(): bool
    {
        return $this->accessAttributes !== [];
    }
}
