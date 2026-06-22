<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;

class StubReaderChild extends StubReaderBase
{
    use StubReaderTrait;

    #[ActivityStub(GreetingActivity::class, startToClose: 60)]
    private $fromChild;

    private $plain;
}
