<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceConfigurationException;
use Psr\Container\ContainerInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;

class GrpcServiceRegistry
{
    /** @var list<GrpcServiceDescriptor> */
    private array $descriptors = [];

    public function __construct(
        private readonly ContainerInterface $serviceLocator,
    )
    {
    }

    /**
     * @param class-string<ServiceInterface> $interface
     * @param class-string $handlerClass
     */
    public function addService(string $interface, string $serviceId, string $handlerClass): void
    {
        $serviceName = self::readServiceName($interface);

        $this->descriptors[] = new GrpcServiceDescriptor($serviceName, $interface, $serviceId, $handlerClass);
    }

    /**
     * @return list<GrpcServiceDescriptor>
     */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    public function getService(GrpcServiceDescriptor $descriptor): ServiceInterface
    {
        $service = $this->serviceLocator->get($descriptor->serviceId);

        if (!$service instanceof ServiceInterface) {
            throw new GrpcServiceConfigurationException(sprintf('gRPC handler "%s" must implement %s, got %s', $descriptor->serviceId, ServiceInterface::class, get_debug_type($service)));
        }

        return $service;
    }

    /**
     * @param class-string $interface
     */
    public static function readServiceName(string $interface): string
    {
        $reflection = new \ReflectionClass($interface);
        $hasName = $reflection->hasConstant('NAME');

        if (!$hasName) {
            throw new GrpcServiceConfigurationException(sprintf('gRPC service interface %s declares no NAME constant; generate it with protoc-gen-php-grpc', $interface));
        }

        $serviceName = $reflection->getConstant('NAME');

        if (!is_string($serviceName)) {
            throw new GrpcServiceConfigurationException(sprintf('Constant %s::NAME must be a string', $interface));
        }

        return $serviceName;
    }
}
