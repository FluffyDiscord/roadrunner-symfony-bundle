<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\WorkflowDefaults;
use Temporal\Common\IdReusePolicy;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
#[WorkflowDefaults(queue: 'billing', reusePolicy: IdReusePolicy::AllowDuplicate)]
interface DefaultedWorkflowInterface
{
    #[WorkflowMethod(name: 'Defaulted')]
    public function run(): \Generator;
}
