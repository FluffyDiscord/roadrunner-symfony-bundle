<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Workflow;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;

/**
 * @internal Resolves the #[ActivityStub] properties of a workflow class. Walks the class hierarchy
 *           (a single getProperties() omits inherited private properties) and de-duplicates by
 *           property name, keeping the most-derived occurrence. Matches #[ActivityStub] and any
 *           subclass of it (IS_INSTANCEOF), so projects can ship predefined stub attributes. Shared
 *           by the hydrator, the compiler-pass guard and the debug commands so they cannot drift.
 */
final class ActivityStubReader
{
    /**
     * @param \ReflectionClass<object> $class
     * @return list<array{0: \ReflectionProperty, 1: ActivityStub}>
     */
    public static function pairs(\ReflectionClass $class): array
    {
        $pairs = [];
        $seen = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                $name = $property->getName();
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $attributes = $property->getAttributes(ActivityStub::class, \ReflectionAttribute::IS_INSTANCEOF);
                if ($attributes === []) {
                    continue;
                }

                $pairs[] = [$property, $attributes[0]->newInstance()];
            }
        }

        return $pairs;
    }
}
