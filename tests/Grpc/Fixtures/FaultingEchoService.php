<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcHandlerFaultException;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\StatusCode;

class FaultingEchoService extends EchoService
{
    public function Ping(ContextInterface $ctx, PingRequest $in): PingResponse
    {
        throw GrpcHandlerFaultException::create('handler fault', StatusCode::INTERNAL);
    }
}
