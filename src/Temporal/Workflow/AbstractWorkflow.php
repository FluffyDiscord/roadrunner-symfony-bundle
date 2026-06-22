<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Workflow;

/** Base workflow that hydrates its #[ActivityStub] properties on construction. */
abstract class AbstractWorkflow
{
    public function __construct()
    {
        ActivityStubHydrator::hydrate($this);
    }
}
