<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;

/** A project-defined stub variant: subclasses must re-declare #[Attribute] (it is not inherited). */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class PredefinedActivityStub extends ActivityStub
{
    public function __construct()
    {
        parent::__construct(GreetingActivity::class, queue: 'preset', startToClose: 42, retryAttempts: 7);
    }
}
