<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use Spiral\RoadRunner\GRPC\ServiceInterface;

interface InvalidSignatureInterface extends ServiceInterface
{
    public const NAME = 'bundle.test.Invalid';

    public function Broken(string $notAContext): string;
}
