<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;

class StubReaderBase
{
    #[ActivityStub(GreetingActivity::class, startToClose: 60)]
    private $fromBase;
}
