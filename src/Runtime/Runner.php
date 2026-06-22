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
        private string          $runtimeMode,
    )
    {
    }

    public function run(): int
    {
        $_SERVER['APP_RUNTIME_MODE'] = $this->runtimeMode;

        $this->kernel->boot();

        $registry = $this->kernel->getContainer()->get(WorkerRegistry::class);
        assert($registry instanceof WorkerRegistry);

        $worker = $registry->getWorker($this->mode);

        if (null === $worker) {
            error_log(sprintf('This bundle does not support worker "%s" yet, open issue or make PR', $this->mode));

            return 1;
        }

        $worker->start();

        return 0;
    }
}
