<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

class InvalidSignatureService implements InvalidSignatureInterface
{
    public function Broken(string $notAContext): string
    {
        return $notAContext;
    }
}
