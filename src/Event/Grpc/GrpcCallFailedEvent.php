<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Grpc;

use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Symfony\Contracts\EventDispatcher\Event;

class GrpcCallFailedEvent extends Event
{
    public function __construct(
        public readonly string            $serviceName,
        public readonly string            $methodName,
        public readonly ?ContextInterface $context,
        public readonly ?Message          $request,
        public readonly \Throwable        $throwable,
        public readonly int               $workerStatusCode,
        public readonly float             $durationMs,
    )
    {
    }
}
