<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Transport;

use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\Worker;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Temporal\Worker\Transport\CommandBatch;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Workflow;

class BatchIsolatingHostConnection implements HostConnectionInterface
{
    private bool $isRecycleRequested = false;

    public function __construct(
        private readonly HostConnectionInterface   $hostConnection,
        private readonly EnvironmentInterface      $environment,
        private readonly ServicesResetterInterface $servicesResetter,
        private readonly ?LoggerInterface          $logger = null,
        private readonly ?SentryHubInterface       $sentryHub = null,
    )
    {
    }

    public function waitBatch(): ?CommandBatch
    {
        $batch = $this->hostConnection->waitBatch();
        if ($batch === null) {
            return null;
        }

        if ($this->isRecycleRequested) {
            $this->handBatchToFreshWorker();

            return null;
        }

        $this->sentryHub?->pushScope();

        return $batch;
    }

    public function send(string $frame): void
    {
        Workflow::setCurrentContext(null);

        try {
            $this->hostConnection->send($frame);
        } finally {
            $this->endBatch();
        }
    }

    public function error(\Throwable $error): void
    {
        Workflow::setCurrentContext(null);

        try {
            $this->hostConnection->error($error);
        } finally {
            $this->endBatch();
        }
    }

    public function recycleAfterBatch(): void
    {
        $this->isRecycleRequested = true;
    }

    public function resetServices(): void
    {
        try {
            $this->servicesResetter->reset();
        } catch (\Throwable $resetFailure) {
            $this->recycleAfterBatch();
            $this->reportResetFailure($resetFailure);
        }
    }

    protected function handBatchToFreshWorker(): void
    {
        Worker::createFromEnvironment($this->environment, interceptSideEffects: false)->stop();
    }

    private function endBatch(): void
    {
        $this->resetServices();

        try {
            $this->sentryHub?->getClient()?->flush();
        } catch (\Throwable) {
        }
        try {
            $this->sentryHub?->popScope();
        } catch (\Throwable) {
        }
    }

    private function reportResetFailure(\Throwable $resetFailure): void
    {
        @fwrite(\STDERR, '[roadrunner-symfony] Temporal service reset failed, recycling the worker: ' . $resetFailure . "\n");

        try {
            $this->sentryHub?->captureException($resetFailure);
            $this->logger?->critical('Temporal: service reset failed, recycling the worker', ['exception' => $resetFailure]);
        } catch (\Throwable) {
        }
    }
}
