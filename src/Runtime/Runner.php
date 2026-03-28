<?php

namespace FluffyDiscord\RoadRunnerBundle\Runtime;

use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

readonly class Runner implements RunnerInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private string          $mode,
    )
    {
    }

    public function run(): int
    {
        $this->kernel->boot();

        $registry = $this->kernel->getContainer()->get(WorkerRegistry::class);
        assert($registry instanceof WorkerRegistry);

        $worker = $registry->getWorker($this->mode);

        if (null === $worker) {
            error_log(sprintf('Missing RR worker implementation for %s mode', $this->mode));

            return 1;
        }

        $worker->start();

        return 0;
    }
}
