<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Security;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use Spiral\RoadRunner\GRPC\Exception\UnauthenticatedException;

interface GrpcCallAuthenticatorInterface
{
    /**
     * @throws UnauthenticatedException
     */
    public function authenticate(GrpcMetadata $metadata): void;
}
