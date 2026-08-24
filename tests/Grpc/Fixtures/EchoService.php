<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\CrashRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\FailRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\StatusCode;

class EchoService implements EchoInterface
{
    public function Ping(ContextInterface $ctx, PingRequest $in): PingResponse
    {
        $headers = $ctx->getValue(ResponseHeaders::class);

        if ($headers instanceof ResponseHeaders) {
            $headers->set('x-echo', '1');
        }

        return new PingResponse()->setMessage($in->getMessage())->setPid(getmypid() ?: 0);
    }

    public function Fail(ContextInterface $ctx, FailRequest $in): PingResponse
    {
        throw GRPCException::create('boom', StatusCode::INVALID_ARGUMENT);
    }

    public function Crash(ContextInterface $ctx, CrashRequest $in): PingResponse
    {
        throw new \RuntimeException('crash');
    }

    public function WhoAmI(ContextInterface $ctx, WhoAmIRequest $in): WhoAmIResponse
    {
        return new WhoAmIResponse()->setUser('unit');
    }
}
