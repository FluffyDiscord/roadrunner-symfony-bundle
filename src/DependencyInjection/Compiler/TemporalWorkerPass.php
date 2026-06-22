<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\Exception\ActivityNotAssignedException;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidActivityStubException;
use FluffyDiscord\RoadRunnerBundle\Exception\WorkflowNotAssignedException;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\DefaultTemporalWorker;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\AbstractWorkflow;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\ActivityStubReader;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\HasActivityStubs;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Temporal\Activity\ActivityInterface;
use Temporal\Internal\Support\DateInterval;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Workflow\WorkflowInterface;

/**
 * Records Temporal workflows/activities/workers on the initializer — including SDK markers placed on
 * an interface, which Symfony attribute autoconfiguration cannot see, hence the definition scan.
 *
 * @internal
 */
final class TemporalWorkerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TemporalWorkerInitializer::class)) {
            return;
        }

        $workerInitializer = $container->getDefinition(TemporalWorkerInitializer::class);

        /** @var array<string, true> $allTaskQueues */
        $allTaskQueues = [];

        /** @var array<string, true> $coveredQueues */
        $coveredQueues = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $this->getClassFromDefinition($definition);
            if ($class === null) {
                continue;
            }

            $interfaces = $this->getDefinitionClassInterfaces($definition);
            $reflectionClass = new \ReflectionClass($class);

            if (in_array(TemporalWorkerInterface::class, $interfaces, true)) {
                $definition->addTag('fluffy_discord.roadrunner.temporal.worker');

                foreach ($this->collectTaskQueues($reflectionClass, $interfaces) as $taskQueue) {
                    $coveredQueues[$taskQueue] = true;
                }
                continue;
            }

            $isActivity = $this->hasAttributeInHierarchy($reflectionClass, ActivityInterface::class, $interfaces);
            $isWorkflow = $this->hasAttributeInHierarchy($reflectionClass, WorkflowInterface::class, $interfaces);

            if (!$isActivity && !$isWorkflow) {
                continue;
            }

            $taskQueues = $this->collectTaskQueues($reflectionClass, $interfaces);

            if ($isActivity) {
                if ($taskQueues === []) {
                    throw new ActivityNotAssignedException(sprintf('Activity %s is missing #[%s]', $class, TaskQueue::class));
                }

                $workerInitializer->addMethodCall('addActivity', [$class, $taskQueues]);

                $definition->setShared(false);
                $definition->setPublic(true);
                $definition->addTag('fluffy_discord.roadrunner.temporal.activity', ['taskQueues' => $taskQueues]);
            }

            if ($isWorkflow) {
                if ($taskQueues === []) {
                    throw new WorkflowNotAssignedException(sprintf('Workflow %s is missing #[%s]', $class, TaskQueue::class));
                }

                $workerInitializer->addMethodCall('addWorkflow', [$class, $taskQueues]);

                $definition->addTag('fluffy_discord.roadrunner.temporal.workflow', ['taskQueues' => $taskQueues]);

                $this->validateActivityStubs($class, $reflectionClass);
            }

            foreach ($taskQueues as $taskQueue) {
                $allTaskQueues[$taskQueue] = true;
            }
        }

        $this->registerAutoWorkers($container, array_keys($allTaskQueues), $coveredQueues);
    }

    /**
     * @param class-string             $class
     * @param \ReflectionClass<object> $reflectionClass
     */
    private function validateActivityStubs(string $class, \ReflectionClass $reflectionClass): void
    {
        $pairs = ActivityStubReader::pairs($reflectionClass);
        if ($pairs === []) {
            return;
        }

        if (!is_subclass_of($class, AbstractWorkflow::class) && !method_exists($class, 'initActivityStubs')) {
            throw new InvalidActivityStubException(sprintf(
                'Workflow "%s" has #[ActivityStub] properties but neither extends %s nor uses the %s trait, so its stubs are never hydrated.',
                $class, AbstractWorkflow::class, HasActivityStubs::class,
            ));
        }

        foreach ($pairs as [$property, $stub]) {
            $where = sprintf('%s::$%s', $class, $property->getName());

            if ($property->hasType()) {
                throw new InvalidActivityStubException(sprintf(
                    'Activity stub property %s must be untyped (the SDK ActivityProxy does not implement %s); use a "/** @var %s */" docblock for the IDE instead.',
                    $where, $stub->activity, $stub->activity,
                ));
            }

            if ($stub->startToClose === null && $stub->scheduleToClose === null) {
                throw new InvalidActivityStubException(sprintf('Activity stub %s must set startToClose or scheduleToClose.', $where));
            }

            if (!class_exists($stub->activity) && !interface_exists($stub->activity)) {
                throw new InvalidActivityStubException(sprintf('Activity stub %s references unknown activity "%s".', $where, $stub->activity));
            }

            foreach ([$stub->startToClose, $stub->scheduleToClose, $stub->scheduleToStart, $stub->heartbeat, $stub->retryInitialInterval, $stub->retryMaxInterval] as $duration) {
                if (!is_string($duration)) {
                    continue;
                }

                try {
                    DateInterval::parse($duration, DateInterval::FORMAT_SECONDS);
                } catch (\Throwable $throwable) {
                    throw new InvalidActivityStubException(sprintf('Activity stub %s has an unparseable duration "%s": %s', $where, $duration, $throwable->getMessage()));
                }
            }
        }
    }

    /**
     * @param list<string>        $taskQueues
     * @param array<string, true> $coveredQueues
     */
    private function registerAutoWorkers(ContainerBuilder $container, array $taskQueues, array $coveredQueues): void
    {
        if (!$container->hasDefinition(DefaultTemporalWorker::class)) {
            return;
        }

        $perQueueOptions = $container->hasParameter('fluffy_discord.roadrunner.temporal.worker_options')
            ? $container->getParameter('fluffy_discord.roadrunner.temporal.worker_options')
            : [];

        if (!is_array($perQueueOptions)) {
            $perQueueOptions = [];
        }

        foreach ($taskQueues as $taskQueue) {
            if ($taskQueue === WorkerFactoryInterface::DEFAULT_TASK_QUEUE || isset($coveredQueues[$taskQueue])) {
                continue;
            }

            $serviceId = 'fluffy_discord.roadrunner.temporal.worker.' . preg_replace('/[^A-Za-z0-9_.]/', '_', $taskQueue);
            if ($container->hasDefinition($serviceId)) {
                continue;
            }

            $options = $perQueueOptions[$taskQueue] ?? [];
            if (!is_array($options)) {
                $options = [];
            }

            $definition = new Definition(DefaultTemporalWorker::class, [
                $taskQueue,
                $options,
            ]);
            $definition->setPublic(true);
            $definition->addTag('fluffy_discord.roadrunner.temporal.worker');

            $container->setDefinition($serviceId, $definition);
        }
    }

    /**
     * @param \ReflectionClass<object> $reflectionClass
     * @param list<class-string>       $interfaces
     * @param class-string             $attributeClass
     */
    private function hasAttributeInHierarchy(\ReflectionClass $reflectionClass, string $attributeClass, array $interfaces): bool
    {
        $current = $reflectionClass;
        while ($current !== false) {
            if ($current->getAttributes($attributeClass) !== []) {
                return true;
            }
            $current = $current->getParentClass();
        }

        foreach ($interfaces as $interface) {
            if (!class_exists($interface) && !interface_exists($interface)) {
                continue;
            }
            $interfaceReflection = new \ReflectionClass($interface);
            if ($interfaceReflection->getAttributes($attributeClass) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object> $reflectionClass
     * @param list<class-string>       $interfaces
     * @return list<string>
     */
    private function collectTaskQueues(\ReflectionClass $reflectionClass, array $interfaces): array
    {
        $taskQueues = [];

        $current = $reflectionClass;
        while ($current !== false) {
            foreach ($current->getAttributes(TaskQueue::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $taskQueues[] = $attribute->newInstance()->taskQueue;
            }
            $current = $current->getParentClass();
        }

        foreach ($interfaces as $interface) {
            if (!class_exists($interface) && !interface_exists($interface)) {
                continue;
            }
            $interfaceReflection = new \ReflectionClass($interface);
            foreach ($interfaceReflection->getAttributes(TaskQueue::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $taskQueues[] = $attribute->newInstance()->taskQueue;
            }
        }

        return array_values(array_unique($taskQueues));
    }

    /**
     * @return list<class-string>
     */
    private function getDefinitionClassInterfaces(Definition $definition): array
    {
        $class = $this->getClassFromDefinition($definition);
        if ($class === null) {
            return [];
        }

        $interfaces = class_implements($class);
        if ($interfaces === false) {
            return [];
        }

        return array_values($interfaces);
    }

    /**
     * @return class-string|null
     */
    private function getClassFromDefinition(Definition $definition): ?string
    {
        $class = $definition->getClass();
        if ($class === null) {
            return null;
        }

        try {
            if (!class_exists($class)) {
                return null;
            }
        } catch (\Throwable) {
            // Symfony\Component\ErrorHandler\Error\ClassNotFoundError cannot be caught directly.
            return null;
        }

        return $class;
    }
}
