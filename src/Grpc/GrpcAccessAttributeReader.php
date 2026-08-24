<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use Symfony\Component\Security\Http\Attribute\IsGranted;

class GrpcAccessAttributeReader
{
    /**
     * @param \ReflectionClass<covariant object> $handlerReflection
     * @return list<IsGranted>
     */
    public function read(\ReflectionClass $handlerReflection, \ReflectionMethod $interfaceMethod): array
    {
        $attributeClassExists = class_exists(IsGranted::class);

        if (!$attributeClassExists) {
            return [];
        }

        $classAttributes = $this->readFrom($handlerReflection);
        $methodAttributes = $this->readFrom($handlerReflection->getMethod($interfaceMethod->getName()));

        if ($methodAttributes === []) {
            $methodAttributes = $this->readFrom($interfaceMethod);
        }

        return array_merge($classAttributes, $methodAttributes);
    }

    /**
     * @param \ReflectionClass<covariant object>|\ReflectionMethod $reflection
     * @return list<IsGranted>
     */
    private function readFrom(\ReflectionClass|\ReflectionMethod $reflection): array
    {
        $attributes = [];

        foreach ($reflection->getAttributes(IsGranted::class) as $reflectionAttribute) {
            $attributes[] = $reflectionAttribute->newInstance();
        }

        return $attributes;
    }
}
