<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceConfigurationException;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GrpcRoutingTable
{
    /**
     * @param array<string, GrpcServiceRoute> $routes
     */
    public function __construct(
        private readonly array $routes,
    )
    {
    }

    public static function fromRegistry(GrpcServiceRegistry $registry): self
    {
        $routes = [];

        foreach ($registry->getDescriptors() as $descriptor) {
            $service = $registry->getService($descriptor);
            $routes[$descriptor->serviceName] = self::buildRoute($descriptor, $service);
        }

        return new self($routes);
    }

    public function getRoute(string $serviceName): ?GrpcServiceRoute
    {
        return $this->routes[$serviceName] ?? null;
    }

    /**
     * @return list<GrpcServiceRoute>
     */
    public function getRoutes(): array
    {
        return array_values($this->routes);
    }

    public function hasAccessAttributes(): bool
    {
        foreach ($this->routes as $route) {
            if ($route->hasAccessAttributes()) {
                return true;
            }
        }

        return false;
    }

    private static function buildRoute(GrpcServiceDescriptor $descriptor, ServiceInterface $service): GrpcServiceRoute
    {
        $implementsInterface = $service instanceof $descriptor->interface;

        if (!$implementsInterface) {
            throw new GrpcServiceConfigurationException(sprintf('gRPC handler %s does not implement %s', $descriptor->handlerClass, $descriptor->interface));
        }

        $interfaceReflection = new \ReflectionClass($descriptor->interface);
        $handlerReflection = new \ReflectionClass($service);
        $handlerClassAttributes = self::readAccessAttributes($handlerReflection);
        $methods = [];

        foreach ($interfaceReflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $interfaceMethod) {
            $method = self::parseMethod($descriptor, $interfaceMethod);
            $handlerMethod = $handlerReflection->getMethod($interfaceMethod->getName());
            $methodAttributes = self::readAccessAttributes($handlerMethod);

            if ($methodAttributes === []) {
                $methodAttributes = self::readAccessAttributes($interfaceMethod);
            }

            $accessAttributes = array_merge($handlerClassAttributes, $methodAttributes);
            self::assertSupportedAccessAttributes($descriptor, $interfaceMethod, $accessAttributes);

            $methods[$method->name] = new GrpcMethodRoute($method, $accessAttributes);
        }

        return new GrpcServiceRoute($descriptor->serviceName, $descriptor->interface, $service, $methods);
    }

    private static function parseMethod(GrpcServiceDescriptor $descriptor, \ReflectionMethod $interfaceMethod): Method
    {
        try {
            return Method::parse($interfaceMethod);
        } catch (GRPCExceptionInterface $invalidSignature) {
            throw new GrpcServiceConfigurationException(sprintf('%s::%s() is not a valid gRPC method: %s', $descriptor->interface, $interfaceMethod->getName(), $invalidSignature->getMessage()), previous: $invalidSignature);
        }
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $reflection
     * @return list<IsGranted>
     */
    private static function readAccessAttributes(\ReflectionClass|\ReflectionMethod $reflection): array
    {
        $attributeClassExists = class_exists(IsGranted::class);

        if (!$attributeClassExists) {
            return [];
        }

        $attributes = [];

        foreach ($reflection->getAttributes(IsGranted::class) as $reflectionAttribute) {
            $attributes[] = $reflectionAttribute->newInstance();
        }

        return $attributes;
    }

    /**
     * @param list<IsGranted> $accessAttributes
     */
    private static function assertSupportedAccessAttributes(GrpcServiceDescriptor $descriptor, \ReflectionMethod $interfaceMethod, array $accessAttributes): void
    {
        foreach ($accessAttributes as $accessAttribute) {
            $attributeIsString = is_string($accessAttribute->attribute);
            $subjectIsSupported = $accessAttribute->subject === null || $accessAttribute->subject === 'request';

            if (!$attributeIsString || !$subjectIsSupported) {
                throw new GrpcServiceConfigurationException(sprintf('#[IsGranted] on %s::%s() must use a string attribute and a null or "request" subject for gRPC', $descriptor->handlerClass, $interfaceMethod->getName()));
            }
        }
    }
}
