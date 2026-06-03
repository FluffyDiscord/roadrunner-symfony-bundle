<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\NoCentrifugoResponseProvidedException;
use FluffyDiscord\RoadRunnerBundle\Exception\UnsupportedCentrifugoRequestTypeException;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Payload\PublishResponse;
use RoadRunner\Centrifugo\Payload\RefreshResponse;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Payload\SubRefreshResponse;
use RoadRunner\Centrifugo\Payload\SubscribeResponse;
use RoadRunner\Centrifugo\Request;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

/**
 * Centrifugo (RPC/proxy) worker: one response frame per request, STDERR/Sentry logging and a
 * controlled client signal on failure.
 */
class CentrifugoWorker implements WorkerInterface
{
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly bool                       $lazyBoot,
        private readonly bool                       $debug,
        private readonly KernelInterface            $kernel,
        private readonly RoadRunnerCentrifugoWorker $worker,
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

        // Captured by reference so the shutdown closure always sees the latest per-iteration values.
        $handlingRequest = false;
        $responded = false;
        $currentRequest = null;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $this->registerShutdown(function () use (&$handlingRequest, &$responded, &$currentRequest): void {
                $this->handleShutdown($handlingRequest, $responded, $currentRequest, error_get_last());
            });
        }

        while ($request = $this->waitRequest()) {
            $event = null;
            $hadException = false;
            $handlingRequest = true;
            $responded = false;
            $currentRequest = $request;

            try {
                $this->sentryHubInterface?->pushScope();

                $this->eventDispatcher->dispatch(new WorkerRequestReceivedEvent());

                $this->kernel->boot();

                $event = match (true) {
                    $request instanceof Request\Connect => new ConnectEvent($request),
                    $request instanceof Request\Publish => new PublishEvent($request),
                    $request instanceof Request\Refresh => new RefreshEvent($request),
                    $request instanceof Request\SubRefresh => new SubRefreshEvent($request),
                    $request instanceof Request\Subscribe => new SubscribeEvent($request),
                    $request instanceof Request\RPC => new RPCEvent($request),
                    $request instanceof Request\Invalid => new InvalidEvent($request),
                    default => throw new UnsupportedCentrifugoRequestTypeException(sprintf('Unsupported $request type: %s', $request::class)),
                };

                /** @var CentrifugoEventInterface $processedEvent */
                $processedEvent = $this->eventDispatcher->dispatch($event);

                if (!$event instanceof InvalidEvent) {
                    $response = $processedEvent->getResponse() ?? match ($event::class) {
                        ConnectEvent::class => new ConnectResponse(),
                        PublishEvent::class => new PublishResponse(),
                        RefreshEvent::class => new RefreshResponse(),
                        SubRefreshEvent::class => new SubRefreshResponse(),
                        SubscribeEvent::class => new SubscribeResponse(),
                        RPCEvent::class => new RPCResponse(),
                        default => throw new NoCentrifugoResponseProvidedException(sprintf('No supported default response found for request type: %s', $request::class)),
                    };

                    $request->respond($response);
                    $responded = true;
                }

                $this->eventDispatcher->dispatch(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));
            } catch (\Throwable $throwable) {
                $hadException = true;

                try {
                    $this->sentryHubInterface?->captureException($throwable);
                } catch (\Throwable) {}

                if (!$responded) {
                    $responded = true;
                    $this->sendThrowableResponse($request, $throwable);
                }

                $this->logError((string)$throwable);

                if ($throwable instanceof \Error) {
                    $this->worker->getWorker()->stop();
                    continue;
                }
            } finally {
                try {
                    if ($hadException && $this->kernel instanceof RebootableInterface) {
                        $this->kernel->reboot(null);
                    }
                } catch (\Throwable $cleanupThrowable) {
                    $this->logError("Fatal worker cleanup error: " . $cleanupThrowable);
                    $this->worker->getWorker()->stop();
                } finally {
                    try {
                        $this->servicesResetter?->reset();
                    } catch (\Throwable $throwable) {
                        $this->logError((string)$throwable);
                        $this->worker->getWorker()->stop();
                    }
                }

                try {
                    $this->sentryHubInterface?->getClient()?->flush();
                } catch (\Throwable) {}
                try {
                    $this->sentryHubInterface?->popScope();
                } catch (\Throwable) {}

                $handlingRequest = false;
                $currentRequest = null;
            }
        }
    }

    /**
     * Invoked from the shutdown function for die()/exit()/fatal that bypass the try/catch: logs the
     * otherwise-invisible failure and best-effort signals the client.
     *
     * @param array{message?: string, file?: string, line?: int}|null $error result of error_get_last()
     */
    protected function handleShutdown(bool $handlingRequest, bool $responded, ?Request\RequestInterface $request, ?array $error): void
    {
        if (!$handlingRequest || $responded || $request === null) {
            return;
        }

        if ($error !== null && isset($error['message']) && str_contains($error['message'], 'Allowed memory size')) {
            @ini_set('memory_limit', '-1');
        }

        try {
            $this->respondToFailedRequest($request, 'Unexpected system error');
        } catch (\Throwable) {}

        $this->logError(
            $error !== null && isset($error['message'])
                ? sprintf('fatal: %s in %s:%d', $error['message'], $error['file'] ?? '?', $error['line'] ?? 0)
                : 'worker terminated via die/exit during request',
        );

        try {
            $this->sentryHubInterface?->captureMessage('RoadRunner Centrifugo worker fatal: ' . ($error['message'] ?? 'die/exit during request'));
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {}
    }

    /**
     * Answer a failed request with a single Centrifugo response frame; error() is a fallback only if
     * sending that frame throws.
     */
    protected function sendThrowableResponse(Request\RequestInterface $request, \Throwable $throwable): void
    {
        try {
            $this->respondToFailedRequest($request, $this->clientMessage($throwable));
        } catch (\Throwable) {
            try {
                $this->worker->getWorker()->error((string)$throwable);
            } catch (\Throwable) {}
        }
    }

    /**
     * Map a failed request to the right Centrifugo signal: drop the connection for lifecycle requests,
     * return a soft error for in-band operations, stay silent for malformed ones.
     */
    protected function respondToFailedRequest(Request\RequestInterface $request, string $clientMessage): void
    {
        match ($this->chooseFailureAction($request)) {
            'disconnect' => $request->disconnect(Response::HTTP_INTERNAL_SERVER_ERROR, $clientMessage),
            'error'      => $request->error(Response::HTTP_INTERNAL_SERVER_ERROR, $clientMessage, true),
            default      => null, // 'none' — Invalid has no worker to respond through
        };
    }

    /**
     * @return 'disconnect'|'error'|'none'
     */
    protected function chooseFailureAction(Request\RequestInterface $request): string
    {
        return match (true) {
            $request instanceof Request\Connect,
            $request instanceof Request\Subscribe => 'disconnect',
            $request instanceof Request\Invalid   => 'none',
            default                               => 'error', // RPC, Publish, Refresh, SubRefresh
        };
    }

    /**
     * Client-facing message. In debug a one-line hint (class + message, capped) — never the stack
     * trace, which would travel to the client.
     */
    protected function clientMessage(\Throwable $throwable): string
    {
        if (!$this->debug) {
            return 'Unexpected system error';
        }

        $message = $throwable->getMessage();
        if (\strlen($message) > 200) {
            $message = \substr($message, 0, 200) . '…';
        }

        return sprintf('%s: %s', $throwable::class, $message);
    }

    protected function waitRequest(): ?Request\RequestInterface
    {
        return $this->worker->waitRequest();
    }

    protected function registerShutdown(callable $handler): void
    {
        register_shutdown_function($handler);
    }

    /**
     * STDERR is the worker-log channel, never the goridge relay — writing here cannot corrupt it.
     */
    protected function logError(string $message): void
    {
        @fwrite(\STDERR, '[roadrunner-symfony] ' . $message . "\n");
    }
}
