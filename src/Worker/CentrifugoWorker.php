<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefusableEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\Refusal;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefusalType;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\BootFailureReporting;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\FatalError;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\NoCentrifugoResponseProvidedException;
use FluffyDiscord\RoadRunnerBundle\Exception\UnsupportedCentrifugoRequestTypeException;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Payload\RefreshResponse;
use RoadRunner\Centrifugo\Payload\SubRefreshResponse;
use RoadRunner\Centrifugo\Request;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

class CentrifugoWorker implements WorkerInterface
{
    use BootFailureReporting;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly bool                       $lazyBoot,
        private readonly bool                       $debug,
        private readonly KernelInterface            $kernel,
        private readonly RoadRunnerCentrifugoWorker $worker,
        private readonly EventDispatcherInterface   $eventDispatcher,
        private readonly ?ServicesResetterInterface $servicesResetter,
        private readonly ?SentryHubInterface        $sentryHubInterface = null,
        private readonly ?DumpCapture               $dumpCapture = null,
    )
    {
    }

    public function start(): void
    {
        $booted = false;

        try {
            if (!$this->lazyBoot) {
                $this->kernel->boot();
                $booted = true;
            }

            $this->eventDispatcher->dispatch(new WorkerBootingEvent());
        } catch (\Throwable $bootThrowable) {
            $this->reportBootFailure($bootThrowable);
        }

        $handlingRequest = false;
        $responded = false;
        $currentRequest = null;
        $isStopRequested = false;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $this->registerShutdown(function () use (&$handlingRequest, &$responded, &$currentRequest): void {
                $this->handleShutdown($handlingRequest, $responded, $currentRequest, FatalError::getLastFatalError());
            });
        }

        while ($request = $this->waitRequest()) {
            if ($isStopRequested) {
                $this->worker->getWorker()->stop();
                break;
            }

            $event = null;
            $hadException = false;
            $handlingRequest = true;
            $responded = false;
            $currentRequest = $request;

            try {
                $this->sentryHubInterface?->pushScope();

                $this->eventDispatcher->dispatch(new WorkerRequestReceivedEvent());

                if (!$booted) {
                    $this->kernel->boot();
                    $booted = true;
                }

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
                    $this->answer($request, $processedEvent);
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
                    $isStopRequested = true;
                    continue;
                }
            } finally {
                try {
                    if ($hadException && $this->kernel instanceof RebootableInterface) {
                        $this->kernel->reboot(null);
                    }
                } catch (\Throwable $cleanupThrowable) {
                    $this->logError("Fatal worker cleanup error: " . $cleanupThrowable);
                    $isStopRequested = true;
                } finally {
                    try {
                        $this->servicesResetter?->reset();
                    } catch (\Throwable $throwable) {
                        $this->logError((string)$throwable);
                        $isStopRequested = true;
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

    private function answer(Request\RequestInterface $request, CentrifugoEventInterface $event): void
    {
        $refusal = $event instanceof RefusableEvent ? $event->getRefusal() : null;
        $response = $event->getResponse();

        match (true) {
            $refusal !== null  => $refusal->sendTo($request),
            $response !== null => $request->respond($response),
            default            => $this->denyByDefault($request),
        };
    }

    private function denyByDefault(Request\RequestInterface $request): void
    {
        match (true) {
            $request instanceof Request\Connect    => $this->getDefaultDenyDisconnect()->sendTo($request),
            $request instanceof Request\Publish,
            $request instanceof Request\Subscribe,
            $request instanceof Request\RPC        => $this->getDefaultDenyError()->sendTo($request),
            $request instanceof Request\Refresh    => $request->respond(new RefreshResponse(expired: true)),
            $request instanceof Request\SubRefresh => $request->respond(new SubRefreshResponse(expired: true)),
            default                                => throw new NoCentrifugoResponseProvidedException(sprintf('No default denial for request type: %s', $request::class)),
        };
    }

    private function getDefaultDenyError(): Refusal
    {
        return new Refusal(RefusalType::Error, 403, 'forbidden');
    }

    private function getDefaultDenyDisconnect(): Refusal
    {
        return new Refusal(RefusalType::Disconnect, 4500, 'forbidden');
    }

    /**
     * @param array{message?: string, file?: string, line?: int}|null $error
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

        $dumpSnapshot = $this->dumpCapture?->getSnapshot();
        $dumpSuffix = $dumpSnapshot?->getLogSuffix() ?? '';

        $this->logError(
            $error !== null && isset($error['message'])
                ? sprintf('fatal: %s in %s:%d', $error['message'], $error['file'] ?? '?', $error['line'] ?? 0) . $dumpSuffix
                : 'worker terminated via die/exit during request' . $dumpSuffix,
        );

        try {
            $this->sentryHubInterface?->captureMessage('RoadRunner Centrifugo worker fatal: ' . ($error['message'] ?? 'die/exit during request'));
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {}
    }

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

    protected function respondToFailedRequest(Request\RequestInterface $request, string $clientMessage): void
    {
        match ($this->chooseFailureAction($request)) {
            'disconnect' => $request->disconnect(Response::HTTP_INTERNAL_SERVER_ERROR, $clientMessage),
            'error'      => $request->error(Response::HTTP_INTERNAL_SERVER_ERROR, $clientMessage, true),
            default      => null,
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
            default                               => 'error',
        };
    }

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

    protected function getBootFailureSentryHub(): ?SentryHubInterface
    {
        return $this->sentryHubInterface;
    }
}
