<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Grpc;

use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Symfony\Contracts\EventDispatcher\Event;

class GrpcCallReceivedEvent extends Event
{
    public function __construct(
        public readonly string           $serviceName,
        public readonly string           $methodName,
        public readonly ServiceInterface $service,
        public readonly Method           $method,
        public readonly ContextInterface $context,
        public readonly Message          $request,
    )
    {
    }
}
