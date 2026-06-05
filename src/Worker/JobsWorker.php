<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

class JobsWorker implements WorkerInterface
{
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly bool                       $lazyBoot,
        private readonly KernelInterface            $kernel,
        private readonly ConsumerInterface          $consumer,
        private readonly RrWorkerInterface          $rrWorker,
        private readonly EventDispatcherInterface   $eventDispatcher,
        private readonly ?ServicesResetterInterface $servicesResetter,
        private readonly ?SentryHubInterface        $sentryHubInterface = null,
    )
    {
    }

    public function start(): void
    {
        if (!$this->lazyBoot) {
            $this->kernel->boot();
        }

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        $handlingTask = false;
        $responded = false;
        $currentTask = null;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $this->registerShutdown(function () use (&$handlingTask, &$responded, &$currentTask): void {
                $this->handleShutdown($handlingTask, $responded, $currentTask, error_get_last());
            });
        }

        while ($task = $this->waitTask()) {
            $hadException = false;
            $handlingTask = true;
            $responded = false;
            $currentTask = $task;

            try {
                $this->sentryHubInterface?->pushScope();

                $this->eventDispatcher->dispatch(new WorkerRequestReceivedEvent());

                $this->kernel->boot();

                $this->eventDispatcher->dispatch(new JobsRunEvent($task));

                if (!$task->isCompleted()) {
                    $task->ack();
                }
                $responded = true;

                $this->eventDispatcher->dispatch(new WorkerResponseSentEvent(Mode::MODE_JOBS));
            } catch (\Throwable $throwable) {
                $hadException = true;

                try {
                    $this->sentryHubInterface?->captureException($throwable);
                } catch (\Throwable) {
                }

                if (!$responded && !$task->isCompleted()) {
                    $responded = true;
                    $this->sendThrowableResponse($task, $throwable);
                }

                $this->logError((string)$throwable);

                if ($throwable instanceof \Error) {
                    $this->rrWorker->stop();
                    continue;
                }
            } finally {
                try {
                    if ($hadException && $this->kernel instanceof RebootableInterface) {
                        $this->kernel->reboot(null);
                    }
                } catch (\Throwable $cleanupThrowable) {
                    $this->logError("Fatal worker cleanup error: " . $cleanupThrowable);
                    $this->rrWorker->stop();
                } finally {
                    try {
                        $this->servicesResetter?->reset();
                    } catch (\Throwable $throwable) {
                        $this->logError((string)$throwable);
                        $this->rrWorker->stop();
                    }
                }

                try {
                    $this->sentryHubInterface?->getClient()?->flush();
                } catch (\Throwable) {
                }
                try {
                    $this->sentryHubInterface?->popScope();
                } catch (\Throwable) {
                }

                $handlingTask = false;
                $currentTask = null;
            }
        }
    }

    /**
     * @param array{message?: string, file?: string, line?: int}|null $error
     */
    protected function handleShutdown(bool $handlingTask, bool $responded, ?ReceivedTaskInterface $task, ?array $error): void
    {
        if (!$handlingTask || $responded || $task === null) {
            return;
        }

        if ($error !== null && isset($error['message']) && str_contains($error['message'], 'Allowed memory size')) {
            @ini_set('memory_limit', '-1');
        }

        try {
            if (!$task->isCompleted()) {
                $task->nack('Worker terminated during task', true);
            }
        } catch (\Throwable) {
        }

        $this->logError(
            $error !== null && isset($error['message'])
                ? sprintf('fatal: %s in %s:%d', $error['message'], $error['file'] ?? '?', $error['line'] ?? 0)
                : 'worker terminated via die/exit during task',
        );

        try {
            $this->sentryHubInterface?->captureMessage('RoadRunner Jobs worker fatal: ' . ($error['message'] ?? 'die/exit during task'));
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {
        }
    }

    protected function sendThrowableResponse(ReceivedTaskInterface $task, \Throwable $throwable): void
    {
        try {
            $task->nack($throwable, true);
        } catch (\Throwable) {
            try {
                $this->rrWorker->error((string)$throwable);
            } catch (\Throwable) {
            }
        }
    }

    protected function waitTask(): ?ReceivedTaskInterface
    {
        return $this->consumer->waitTask();
    }

    protected function registerShutdown(callable $handler): void
    {
        register_shutdown_function($handler);
    }

    protected function logError(string $message): void
    {
        @fwrite(\STDERR, '[roadrunner-symfony] ' . $message . "\n");
    }
}
