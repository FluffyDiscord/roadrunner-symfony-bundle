<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Client;

interface WorkflowLauncherInterface
{
    /** @param class-string $workflowInterface */
    public function of(string $workflowInterface): PendingWorkflowStart;
}
