<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Debug;

use FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceDescriptor;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\Method;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GrpcIntrospector
{
    public function __construct(
        private readonly GrpcServiceRegistry        $serviceRegistry,
        private readonly RoadRunnerYamlConfigReader $configReader,
        private readonly bool                       $securityEnabled,
    )
    {
    }

    /**
     * @return list<GrpcServiceDebugRow>
     */
    public function describe(): array
    {
        $rows = [];

        foreach ($this->serviceRegistry->getDescriptors() as $descriptor) {
            foreach ($this->describeService($descriptor) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function getServerFacts(): GrpcServerFacts
    {
        return GrpcServerFacts::fromConfigSection($this->configReader->getSection('grpc'));
    }

    public function isSecurityEnabled(): bool
    {
        return $this->securityEnabled;
    }

    /**
     * @return list<GrpcServiceDebugRow>
     */
    private function describeService(GrpcServiceDescriptor $descriptor): array
    {
        $interfaceReflection = new \ReflectionClass($descriptor->interface);
        $handlerReflection = new \ReflectionClass($descriptor->handlerClass);
        $rows = [];

        foreach ($interfaceReflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $interfaceMethod) {
            $rows[] = $this->describeMethod($descriptor, $interfaceMethod, $handlerReflection);
        }

        return $rows;
    }

    /**
     * @param \ReflectionClass<object> $handlerReflection
     */
    private function describeMethod(GrpcServiceDescriptor $descriptor, \ReflectionMethod $interfaceMethod, \ReflectionClass $handlerReflection): GrpcServiceDebugRow
    {
        $accessAttributes = $this->describeAccessAttributes($interfaceMethod, $handlerReflection);

        try {
            $method = Method::parse($interfaceMethod);
        } catch (GRPCExceptionInterface $invalidSignature) {
            return new GrpcServiceDebugRow($descriptor->serviceName, $descriptor->interface, $descriptor->handlerClass, $interfaceMethod->getName(), '?', '?', $accessAttributes, $invalidSignature->getMessage());
        }

        return new GrpcServiceDebugRow($descriptor->serviceName, $descriptor->interface, $descriptor->handlerClass, $method->name, $method->inputType, $method->outputType, $accessAttributes, null);
    }

    /**
     * @param \ReflectionClass<object> $handlerReflection
     * @return list<string>
     */
    private function describeAccessAttributes(\ReflectionMethod $interfaceMethod, \ReflectionClass $handlerReflection): array
    {
        $attributeClassExists = class_exists(IsGranted::class);

        if (!$attributeClassExists) {
            return [];
        }

        $hasHandlerMethod = $handlerReflection->hasMethod($interfaceMethod->getName());
        $reflections = [$handlerReflection];

        if ($hasHandlerMethod) {
            $reflections[] = $handlerReflection->getMethod($interfaceMethod->getName());
        }

        $reflections[] = $interfaceMethod;
        $described = [];

        foreach ($reflections as $reflection) {
            foreach ($reflection->getAttributes(IsGranted::class) as $reflectionAttribute) {
                $described[] = $this->describeAttribute($reflectionAttribute->newInstance());
            }
        }

        return array_values(array_unique($described));
    }

    private function describeAttribute(IsGranted $attribute): string
    {
        $attributeLabel = is_string($attribute->attribute) ? $attribute->attribute : get_debug_type($attribute->attribute);

        if ($attribute->subject === null) {
            return $attributeLabel;
        }

        $subjectLabel = is_string($attribute->subject) ? $attribute->subject : get_debug_type($attribute->subject);

        return $attributeLabel . ' (subject: ' . $subjectLabel . ')';
    }
}
