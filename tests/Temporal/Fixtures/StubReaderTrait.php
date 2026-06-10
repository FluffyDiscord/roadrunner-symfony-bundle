<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;

trait StubReaderTrait
{
    #[ActivityStub(GreetingActivity::class, startToClose: 60)]
    private $fromTrait;
}
