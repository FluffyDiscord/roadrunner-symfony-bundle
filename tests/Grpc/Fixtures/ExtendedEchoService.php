<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\CrashRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\FailRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;

class ExtendedEchoService implements ExtendedEchoInterface
{
    public function Ping(ContextInterface $ctx, PingRequest $in): PingResponse
    {
        return new PingResponse();
    }

    public function Fail(ContextInterface $ctx, FailRequest $in): PingResponse
    {
        return new PingResponse();
    }

    public function Crash(ContextInterface $ctx, CrashRequest $in): PingResponse
    {
        return new PingResponse();
    }

    public function WhoAmI(ContextInterface $ctx, WhoAmIRequest $in): WhoAmIResponse
    {
        return new WhoAmIResponse();
    }
}
