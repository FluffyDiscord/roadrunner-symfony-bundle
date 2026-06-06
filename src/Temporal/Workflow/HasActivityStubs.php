<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Workflow;

/** For workflows that need their own constructor: call initActivityStubs() from it. */
trait HasActivityStubs
{
    final protected function initActivityStubs(): void
    {
        ActivityStubHydrator::hydrate($this);
    }
}
