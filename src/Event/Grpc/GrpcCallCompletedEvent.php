<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Grpc;

use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Symfony\Contracts\EventDispatcher\Event;

class GrpcCallCompletedEvent extends Event
{
    public function __construct(
        public readonly string           $serviceName,
        public readonly string           $methodName,
        public readonly ContextInterface $context,
        public readonly Message          $request,
        public readonly Message          $response,
        public readonly float            $durationMs,
    )
    {
    }
}
