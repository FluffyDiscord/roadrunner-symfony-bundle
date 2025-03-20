<?php

namespace FluffyDiscord\RoadRunnerBundle\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

trait RoadRunnerMicroKernelTrait
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if (true === $this->booted) {
            return;
        }

        if (null === $this->container) {
            $reflectionClass = new \ReflectionClass($this);
            $preBootMethod = $reflectionClass->getMethod("preBoot");
            $preBootMethod->invoke($this);
        }

        foreach ($this->getBundles() as $bundle) {
            $bundle->setContainer($this->container);
            $bundle->boot();
        }

        $this->booted = true;
    }
}