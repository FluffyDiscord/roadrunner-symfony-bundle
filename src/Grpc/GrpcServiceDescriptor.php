<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use Spiral\RoadRunner\GRPC\ServiceInterface;

readonly class GrpcServiceDescriptor
{
    /**
     * @param class-string<ServiceInterface> $interface
     * @param class-string $handlerClass
     */
    public function __construct(
        public string $serviceName,
        public string $interface,
        public string $serviceId,
        public string $handlerClass,
    )
    {
    }
}
