<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Client;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\WorkflowDefaults;
use Temporal\Client\WorkflowClientInterface;

final class WorkflowLauncher implements WorkflowLauncherInterface
{
    public function __construct(
        private readonly WorkflowClientInterface $client,
    )
    {
    }

    /** @param class-string $workflowInterface */
    public function of(string $workflowInterface): PendingWorkflowStart
    {
        return new PendingWorkflowStart($this->client, $workflowInterface, self::defaultsOf($workflowInterface));
    }

    /** @param class-string $workflowInterface */
    private static function defaultsOf(string $workflowInterface): ?WorkflowDefaults
    {
        foreach ((new \ReflectionClass($workflowInterface))->getAttributes(WorkflowDefaults::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            return $attribute->newInstance();
        }

        return null;
    }
}
