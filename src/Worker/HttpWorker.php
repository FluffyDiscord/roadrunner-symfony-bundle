<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\BootFailureReporting;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\FatalError;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\MinimalErrorPage;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\WorkerErrorResponder;
use FluffyDiscord\RoadRunnerBundle\Factory\BinaryFileResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\DefaultResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\ServerParamsFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedJsonResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\SymfonyRequestFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Http\InformationalHeaders;
use Nyholm\Psr7;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpWorker implements WorkerInterface
{
    use BootFailureReporting;

    private SymfonyRequestFactoryInterface $symfonyRequestFactory;
    private ServerParamsFactory $serverParamsFactory;
    private Psr7\Factory\Psr17Factory $psrFactory;

    public static ?\Spiral\RoadRunner\Http\HttpWorker $currentHttpWorker = null;

    /**
     * True only while WorkerWarmupRunner executes the boot-time warmers. The
     * headers_send() polyfill checks this to swallow informational (1xx) responses such
     * as Early Hints: at boot there is no real RoadRunner request frame to write them
     * to, and emitting one corrupts the worker protocol so it never reaches "ready".
     */
    public static bool $bootWarmupInProgress = false;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly bool                       $lazyBoot,
        private readonly KernelInterface            $kernel,
        private readonly EventDispatcherInterface   $eventDispatcher,
        private readonly bool                       $debug,
        private readonly ?ServicesResetterInterface $servicesResetter,
        private readonly ?SentryHubInterface        $sentryHubInterface = null,
        ?HttpFoundationFactoryInterface             $httpFoundationFactory = null,
        ?SymfonyRequestFactoryInterface             $symfonyRequestFactory = null,
        private readonly ?DumpCapture               $dumpCapture = null,
    )
    {
        $this->psrFactory = new Psr7\Factory\Psr17Factory();
        $this->symfonyRequestFactory = $symfonyRequestFactory ?? new Psr7SymfonyRequestFactory($httpFoundationFactory);
        $this->serverParamsFactory = new ServerParamsFactory();
    }

    protected function createPsr7Worker(): RoadRunner\Http\PSR7Worker
    {
        return new RoadRunner\Http\PSR7Worker(
            RoadRunner\Worker::create(),
            $this->psrFactory,
            $this->psrFactory,
            $this->psrFactory,
        );
    }

    public function start(): void
    {
        ignore_user_abort(true);

        $worker = $this->createPsr7Worker();
        self::$currentHttpWorker = $worker->getHttpWorker();

        if (!\function_exists('headers_send')) {
            require_once __DIR__ . '/../Resources/headers_send_polyfill.php';
        }

        $bootThrowable = null;

        try {
            if (!$this->lazyBoot) {
                $this->kernel->boot();

                new \ReflectionClass(StreamedJsonResponse::class);
                new \ReflectionClass(StreamedResponse::class);
                new \ReflectionClass(BinaryFileResponse::class);
            }

            $this->eventDispatcher->dispatch(new WorkerBootingEvent());
        } catch (\Throwable $throwable) {
            $bootThrowable = $throwable;
        }

        if ($bootThrowable !== null) {
            $this->reportBootFailure($bootThrowable);

            $shouldServeBootFailurePage = $this->shouldServeBootFailurePage();

            if ($shouldServeBootFailurePage) {
                $this->serveBootFailure($worker, $bootThrowable);

                return;
            }
        }

        $handlingRequest = false;
        $responseStarted = false;
        $responseSent = false;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $this->registerShutdown(function () use ($worker, &$handlingRequest, &$responseStarted): void {
                $this->handleShutdown($worker, $handlingRequest, $responseStarted, FatalError::getLastFatalError());
            });
        }

        while (true) {
            InformationalHeaders::forgetSentHeaders();

            $symfonyRequest = null;
            $symfonyResponse = null;
            $content = null;
            $hadException = false;
            $handlingRequest = false;
            $responseStarted = false;
            $responseSent = false;

            try {
                $rrRequest = $worker->getHttpWorker()->waitRequest();
                if ($rrRequest === null) {
                    break;
                }
            } catch (\Throwable) {
                $worker->respond(new Psr7\Response(Response::HTTP_I_AM_A_TEAPOT));
                continue;
            }

            $handlingRequest = true;

            try {
                $this->sentryHubInterface?->pushScope();

                $this->eventDispatcher->dispatch(new WorkerRequestReceivedEvent());

                $server = $this->serverParamsFactory->createServerParams($rrRequest);
                $symfonyRequest = $this->symfonyRequestFactory->createRequest($rrRequest, $server);
                $symfonyResponse = $this->kernel->handle($symfonyRequest);

                $content = match (true) {
                    $symfonyResponse instanceof StreamedJsonResponse => StreamedJsonResponseWrapper::wrap($symfonyResponse),
                    $symfonyResponse instanceof StreamedResponse => StreamedResponseWrapper::wrap($symfonyResponse),
                    $symfonyResponse instanceof BinaryFileResponse => BinaryFileResponseWrapper::wrap($symfonyResponse, $symfonyRequest),
                    default => DefaultResponseWrapper::wrap($symfonyResponse),
                };

                /** @var array<string, array<string>> $allHeaders */
                $allHeaders = $symfonyResponse->headers->all();
                $headers = InformationalHeaders::getUnsentHeaders($allHeaders);

                if ($this->debug) {
                    $this->logHeadersThatReachTheClientTwice($allHeaders, $headers);
                }

                $responseStarted = true;
                $worker->getHttpWorker()->respond(
                    $symfonyResponse->getStatusCode(),
                    $content,
                    $headers,
                );
                $responseSent = true;

                $this->eventDispatcher->dispatch(new WorkerResponseSentEvent(RoadRunner\Environment\Mode::MODE_HTTP));
            } catch (\Throwable $throwable) {
                $hadException = true;

                try {
                    $this->sentryHubInterface?->captureException($throwable);
                } catch (\Throwable) {
                }

                if (!$responseStarted) {
                    $responseStarted = true;
                    $this->sendThrowableResponse($worker, $throwable);
                    $responseSent = true;
                }

                $this->logError((string)$throwable);

                if ($throwable instanceof \Error) {
                    $worker->getWorker()->stop();
                    continue;
                }

            } finally {
                try {
                    if ($symfonyRequest !== null && $symfonyResponse !== null && $this->kernel instanceof TerminableInterface) {
                        $this->kernel->terminate($symfonyRequest, $symfonyResponse);
                    }

                    if ($hadException && $this->kernel instanceof RebootableInterface) {
                        $this->kernel->reboot(null);
                    }
                } catch (\Throwable $cleanupThrowable) {
                    $this->logError("Fatal worker cleanup error: " . $cleanupThrowable);
                    $worker->getWorker()->stop();
                } finally {
                    try {
                        $this->servicesResetter?->reset();
                    } catch (\Throwable $throwable) {
                        $this->logError((string)$throwable);
                        $worker->getWorker()->stop();
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

                $handlingRequest = false;

                unset($rrRequest, $symfonyRequest, $symfonyResponse, $content);
            }
        }
    }

    /**
     * @param array{message?: string, file?: string, line?: int}|null $error
     */
    protected function handleShutdown(
        RoadRunner\Http\PSR7Worker $worker,
        bool                       $handlingRequest,
        bool                       $responseStarted,
        ?array                     $error,
    ): void
    {
        if (!$handlingRequest || $responseStarted) {
            return;
        }

        if ($error !== null && isset($error['message']) && str_contains($error['message'], 'Allowed memory size')) {
            @ini_set('memory_limit', '-1');
        }

        $dumpSnapshot = $this->dumpCapture?->getSnapshot();

        try {
            if ($this->debug) {
                $errorPageHeaders = InformationalHeaders::getUnsentHeaders(['Content-Type' => ['text/html; charset=utf-8']]);

                $worker->getHttpWorker()->respond(
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    MinimalErrorPage::render(Response::HTTP_INTERNAL_SERVER_ERROR, $error, null, $dumpSnapshot),
                    $errorPageHeaders,
                    true,
                );
            } else {
                $worker->getHttpWorker()->respond(Response::HTTP_INTERNAL_SERVER_ERROR, '', [], true);
            }
        } catch (\Throwable) {
            try {
                $worker->getWorker()->error($error['message'] ?? 'Worker terminated during request');
            } catch (\Throwable) {
            }
        }

        $dumpSuffix = $dumpSnapshot?->getLogSuffix() ?? '';

        $this->logError(
            $error !== null && isset($error['message'])
                ? sprintf('fatal: %s in %s:%d', $error['message'], $error['file'] ?? '?', $error['line'] ?? 0) . $dumpSuffix
                : 'worker terminated via die/exit during request' . $dumpSuffix,
        );

        try {
            $this->sentryHubInterface?->captureMessage('RoadRunner worker fatal: ' . ($error['message'] ?? 'die/exit during request'));
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {
        }
    }

    protected function sendThrowableResponse(RoadRunner\Http\PSR7Worker $worker, \Throwable $throwable): void
    {
        $this->getThrowableResponder()->sendThrowableResponse($worker, $throwable);
    }

    protected function getThrowableResponder(): WorkerErrorResponder
    {
        return new WorkerErrorResponder($this->debug);
    }

    protected function shouldServeBootFailurePage(): bool
    {
        return $this->debug;
    }

    protected function serveBootFailure(RoadRunner\Http\PSR7Worker $worker, \Throwable $throwable): void
    {
        try {
            $bootFailureRequest = $worker->getHttpWorker()->waitRequest();
        } catch (\Throwable) {
            return;
        }

        if ($bootFailureRequest === null) {
            return;
        }

        $this->sendThrowableResponse($worker, $throwable);
    }

    /**
     * @param array<string, array<string>> $allHeaders
     * @param array<string, array<string>> $unsentHeaders
     */
    private function logHeadersThatReachTheClientTwice(array $allHeaders, array $unsentHeaders): void
    {
        foreach ($this->getHeadersRejectedWhenDuplicated() as $headerName) {
            $normalizedName = strtolower($headerName);
            $sentAsInformationalValues = InformationalHeaders::getSentValues($normalizedName);
            $finalValues = $unsentHeaders[$normalizedName] ?? [];
            $wireValues = array_merge($sentAsInformationalValues, $finalValues);
            $wireValueCount = \count($wireValues);

            if ($wireValueCount < 2) {
                continue;
            }

            $this->logError(sprintf(
                'response sends the %s header %d times (%s); nginx rejects a duplicated %s with 502 Bad Gateway',
                $headerName,
                $wireValueCount,
                implode(', ', $wireValues),
                $headerName,
            ));
        }

        $this->logStrandedInformationalHeaders($allHeaders);
    }

    /**
     * @param array<string, array<string>> $allHeaders
     */
    private function logStrandedInformationalHeaders(array $allHeaders): void
    {
        $hasSentHeaders = InformationalHeaders::hasSentHeaders();

        if (!$hasSentHeaders) {
            return;
        }

        foreach (InformationalHeaders::getSentHeaderNames() as $normalizedName) {
            $sentAsInformationalValues = InformationalHeaders::getSentValues($normalizedName);
            $finalValues = $allHeaders[$normalizedName] ?? [];
            $strandedValues = array_diff($sentAsInformationalValues, $finalValues);

            if ($strandedValues === []) {
                continue;
            }

            $this->logError(sprintf(
                'the %s header changed after it was sent as an early hint; RoadRunner cannot retract a header, so the client also receives the stale value (%s)',
                $normalizedName,
                implode(', ', $strandedValues),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function getHeadersRejectedWhenDuplicated(): array
    {
        return ['Content-Length', 'Transfer-Encoding'];
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