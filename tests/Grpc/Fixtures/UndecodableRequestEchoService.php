<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;

class UndecodableRequestEchoService implements UndecodableRequestEchoInterface
{
    public bool $wasCalled = false;

    public function Ping(ContextInterface $ctx, UndecodableRequest $in): PingResponse
    {
        $this->wasCalled = true;

        return new PingResponse();
    }
}
