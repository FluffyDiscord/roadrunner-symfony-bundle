<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OverriddenAccessEchoService extends GuardedEchoService
{
    #[IsGranted('ROLE_OVERRIDE')]
    public function WhoAmI(ContextInterface $ctx, WhoAmIRequest $in): WhoAmIResponse
    {
        return new WhoAmIResponse();
    }
}
