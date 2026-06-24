<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\MinimalErrorPage;
use FluffyDiscord\RoadRunnerBundle\Factory\BinaryFileResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\DefaultResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedJsonResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedResponseWrapper;
use Nyholm\Psr7;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpWorker implements WorkerInterface
{
    private HttpFoundationFactoryInterface $httpFoundationFactory;
    private Psr7\Factory\Psr17Factory $psrFactory;

    public static ?\Spiral\RoadRunner\Http\HttpWorker $currentHttpWorker = null;

    /**
     * True only while the worker is serving its own early-router-initialization dummy
     * request at boot (see DUMMY_REQUEST_ATTRIBUTE). The headers_send() polyfill checks
     * this to swallow informational (1xx) responses such as Early Hints: at boot there is
     * no real RoadRunner request frame to write them to, and emitting one corrupts the
     * worker protocol so it never reaches "ready".
     */
    public static bool $bootWarmupInProgress = false;

    public const string DUMMY_REQUEST_ATTRIBUTE = "rr_dummy_request";

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly bool                       $earlyRouterInitialization,
        private readonly bool                       $lazyBoot,
        private readonly KernelInterface            $kernel,
        private readonly EventDispatcherInterface   $eventDispatcher,
        private readonly bool                       $debug,
        private readonly ?ServicesResetterInterface $servicesResetter,
        private readonly ?SentryHubInterface        $sentryHubInterface = null,
        ?HttpFoundationFactoryInterface             $httpFoundationFactory = null,
    )
    {
        $this->psrFactory = new Psr7\Factory\Psr17Factory();
        $this->httpFoundationFactory = $httpFoundationFactory ?? new HttpFoundationFactory();
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

        if (!$this->lazyBoot) {
            $this->kernel->boot();

            if ($this->earlyRouterInitialization) {
                self::$bootWarmupInProgress = true;
                try {
                    $this->kernel->handle(new Request(attributes: [self::DUMMY_REQUEST_ATTRIBUTE => true]));
                } finally {
                    self::$bootWarmupInProgress = false;
                }
            }

            new \ReflectionClass(StreamedJsonResponse::class);
            new \ReflectionClass(StreamedResponse::class);
            new \ReflectionClass(BinaryFileResponse::class);
        }

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        $handlingRequest = false;
        $responseStarted = false;
        $responseSent = false;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $this->registerShutdown(function () use ($worker, &$handlingRequest, &$responseStarted): void {
                $this->handleShutdown($worker, $handlingRequest, $responseStarted, error_get_last());
            });
        }

        while (true) {
            $symfonyRequest = null;
            $symfonyResponse = null;
            $content = null;
            $hadException = false;
            $handlingRequest = false;
            $responseStarted = false;
            $responseSent = false;

            try {
                $request = $worker->waitRequest();
                if ($request === null) {
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

                $symfonyRequest = $this->httpFoundationFactory->createRequest($request);
                $symfonyResponse = $this->kernel->handle($symfonyRequest);

                $content = match (true) {
                    $symfonyResponse instanceof StreamedJsonResponse => StreamedJsonResponseWrapper::wrap($symfonyResponse),
                    $symfonyResponse instanceof StreamedResponse => StreamedResponseWrapper::wrap($symfonyResponse),
                    $symfonyResponse instanceof BinaryFileResponse => BinaryFileResponseWrapper::wrap($symfonyResponse, $symfonyRequest),
                    default => DefaultResponseWrapper::wrap($symfonyResponse),
                };

                /** @var array<array<string>> $headers */
                $headers = $symfonyResponse->headers->all();

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

                unset($request, $symfonyRequest, $symfonyResponse, $content);
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

        try {
            if ($this->debug) {
                $worker->getHttpWorker()->respond(
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    MinimalErrorPage::render(Response::HTTP_INTERNAL_SERVER_ERROR, $error),
                    ['Content-Type' => ['text/html; charset=utf-8']],
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

        $this->logError(
            $error !== null && isset($error['message'])
                ? sprintf('fatal: %s in %s:%d', $error['message'], $error['file'] ?? '?', $error['line'] ?? 0)
                : 'worker terminated via die/exit during request',
        );

        try {
            $this->sentryHubInterface?->captureMessage('RoadRunner worker fatal: ' . ($error['message'] ?? 'die/exit during request'));
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {
        }
    }

    protected function sendThrowableResponse(RoadRunner\Http\PSR7Worker $worker, \Throwable $throwable): void
    {
        try {
            if ($this->debug) {
                try {
                    $flattenException = $this->renderHtmlError($throwable);
                    $worker->respond(new Psr7\Response(
                        $flattenException->getStatusCode(),
                        $flattenException->getHeaders(),
                        $flattenException->getAsString(),
                    ));
                } catch (\Throwable) {
                    $worker->respond(new Psr7\Response(
                        Response::HTTP_INTERNAL_SERVER_ERROR,
                        ['Content-Type' => 'text/html; charset=utf-8'],
                        MinimalErrorPage::render(Response::HTTP_INTERNAL_SERVER_ERROR, null, (string)$throwable),
                    ));
                }
            } else {
                $worker->respond(new Psr7\Response(Response::HTTP_INTERNAL_SERVER_ERROR));
            }
        } catch (\Throwable) {
            try {
                $worker->getWorker()->error((string)$throwable);
            } catch (\Throwable) {
            }
        }
    }

    protected function renderHtmlError(\Throwable $throwable): FlattenException
    {
        return new HtmlErrorRenderer(true)->render($throwable);
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