<?php

namespace FluffyDiscord\RoadRunnerBundle\Runtime;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

class Runtime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        $rrMode = getenv("RR_MODE");
        if ($application instanceof KernelInterface && $rrMode !== false) {
            return new Runner($application, $rrMode);
        }

        return parent::getRunner($application);
    }
}
