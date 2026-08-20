<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceDuplicateNameException;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceInterfaceMissingException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class GrpcServicePass implements CompilerPassInterface
{
    public const TAG = 'fluffy_discord.roadrunner.grpc.service';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(GrpcServiceRegistry::class)) {
            return;
        }

        $registry = $container->getDefinition(GrpcServiceRegistry::class);
        /** @var array<string, Reference> $locatorReferences */
        $locatorReferences = [];
        /** @var array<string, string> $serviceIdsByName */
        $serviceIdsByName = [];

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $this->getClassFromDefinition($container, $definition);

            if ($class === null) {
                continue;
            }

            foreach ($this->collectServiceInterfaces($class) as $interface) {
                $serviceName = GrpcServiceRegistry::readServiceName($interface);
                $this->assertUniqueName($serviceIdsByName, $serviceName, $serviceId);
                $serviceIdsByName[$serviceName] = $serviceId;

                $registry->addMethodCall('addService', [$interface, $serviceId, $class]);
                $locatorReferences[$serviceId] = new Reference($serviceId);
            }
        }

        $registry->replaceArgument(0, ServiceLocatorTagPass::register($container, $locatorReferences));
    }

    /**
     * @return class-string|null
     */
    private function getClassFromDefinition(ContainerBuilder $container, Definition $definition): ?string
    {
        if ($definition->isAbstract() || $definition->isSynthetic()) {
            return null;
        }

        $class = $definition->getClass();

        if ($class === null) {
            return null;
        }

        $resolvedClass = $container->getParameterBag()->resolveValue($class);

        if (!is_string($resolvedClass) || !class_exists($resolvedClass)) {
            return null;
        }

        return $resolvedClass;
    }

    /**
     * @param class-string $class
     * @return list<class-string<ServiceInterface>>
     */
    private function collectServiceInterfaces(string $class): array
    {
        $interfaces = [];

        foreach (class_implements($class) as $interface) {
            if ($this->declaresServiceName($interface)) {
                $interfaces[] = $interface;
            }
        }

        if ($interfaces === []) {
            throw new GrpcServiceInterfaceMissingException(sprintf('%s is tagged as a gRPC service but implements no protoc-gen-php-grpc generated interface (an interface extending %s that declares a NAME constant)', $class, ServiceInterface::class));
        }

        return $interfaces;
    }

    /**
     * @param class-string $interface
     * @phpstan-assert-if-true class-string<ServiceInterface> $interface
     */
    private function declaresServiceName(string $interface): bool
    {
        $extendsServiceInterface = is_subclass_of($interface, ServiceInterface::class);

        if (!$extendsServiceInterface) {
            return false;
        }

        $reflection = new \ReflectionClass($interface);
        $hasName = $reflection->hasConstant('NAME');

        if (!$hasName) {
            return false;
        }

        $declaringClass = new \ReflectionClassConstant($interface, 'NAME')->getDeclaringClass();

        return $declaringClass->getName() === $interface;
    }

    /**
     * @param array<string, string> $serviceIdsByName
     */
    private function assertUniqueName(array $serviceIdsByName, string $serviceName, string $serviceId): void
    {
        $existingServiceId = $serviceIdsByName[$serviceName] ?? null;

        if ($existingServiceId !== null && $existingServiceId !== $serviceId) {
            throw new GrpcServiceDuplicateNameException(sprintf('gRPC service "%s" is implemented by both "%s" and "%s"; only one handler per service is allowed', $serviceName, $existingServiceId, $serviceId));
        }
    }
}
