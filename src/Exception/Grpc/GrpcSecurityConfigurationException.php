<?php

namespace FluffyDiscord\RoadRunnerBundle\Exception\Grpc;

use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

class GrpcSecurityConfigurationException extends GRPCException
{
    protected const CODE = StatusCode::INTERNAL;
}
