<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use Spiral\RoadRunner\GRPC\ServiceInterface;

readonly class GrpcServiceRoute
{
    /**
     * @param class-string<ServiceInterface> $interface
     * @param array<string, GrpcMethodRoute> $methods
     */
    public function __construct(
        public string           $serviceName,
        public string           $interface,
        public ServiceInterface $service,
        public array            $methods,
    )
    {
    }

    public function getMethod(string $methodName): ?GrpcMethodRoute
    {
        return $this->methods[$methodName] ?? null;
    }

    public function hasAccessAttributes(): bool
    {
        foreach ($this->methods as $methodRoute) {
            if ($methodRoute->hasAccessAttributes()) {
                return true;
            }
        }

        return false;
    }
}
