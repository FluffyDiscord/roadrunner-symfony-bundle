<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;

interface UndecodableRequestEchoInterface extends ServiceInterface
{
    public const NAME = 'bundle.test.UndecodableEcho';

    public function Ping(ContextInterface $ctx, UndecodableRequest $in): PingResponse;
}
