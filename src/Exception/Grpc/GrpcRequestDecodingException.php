<?php

namespace FluffyDiscord\RoadRunnerBundle\Exception\Grpc;

use Spiral\RoadRunner\GRPC\Exception\InvokeException;
use Spiral\RoadRunner\GRPC\StatusCode;

class GrpcRequestDecodingException extends InvokeException
{
    protected const CODE = StatusCode::INTERNAL;
}
