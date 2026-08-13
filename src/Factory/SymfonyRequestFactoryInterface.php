<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Symfony\Component\HttpFoundation\Request;

interface SymfonyRequestFactoryInterface
{
    /**
     * @param array<array-key, mixed> $server
     */
    public function createRequest(RoadRunnerRequest $rrRequest, array $server): Request;
}
