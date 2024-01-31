<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

interface WorkerInterface
{
    public function start(): void;
}
