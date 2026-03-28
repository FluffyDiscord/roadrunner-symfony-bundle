<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Factory\BinaryFileResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\DefaultResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedJsonResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedResponseWrapper;
use Nyholm\Psr7;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpWorker implements WorkerInterface
{
    private HttpFoundationFactoryInterface $httpFoundationFactory;
    private Psr7\Factory\Psr17Factory $psrFactory;

    public const string DUMMY_REQUEST_ATTRIBUTE = "rr_dummy_request";

    public function __construct(
        private readonly bool                     $earlyRouterInitialization,
        private readonly bool                     $lazyBoot,
        private readonly KernelInterface          $kernel,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly bool                     $debug,
        private readonly ?ServicesResetterInterface $servicesResetter,
        private readonly ?SentryHubInterface      $sentryHubInterface = null,
        ?HttpFoundationFactoryInterface           $httpFoundationFactory = null,
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
        $worker = $this->createPsr7Worker();

        if (!$this->lazyBoot) {
            $this->kernel->boot();

            // Initialize routing and other lazy services that Symfony has.
            // Reduces first real request response time more than 50%, YMMW
            if ($this->earlyRouterInitialization) {
                $this->kernel->handle(new Request(attributes: [self::DUMMY_REQUEST_ATTRIBUTE => true]));
            }

            // Preload reflections, up to 2ms savings for each, YMMW
            new \ReflectionClass(StreamedJsonResponse::class);
            new \ReflectionClass(StreamedResponse::class);
            new \ReflectionClass(BinaryFileResponse::class);
        }

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        while (true) {
            $symfonyRequest = null;
            $symfonyResponse = null;
            $content = null;
            $hadException = false;
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
                } catch (\Throwable) {}

                if(!$responseSent) {
                    if($this->debug) {
                        $worker->respond(new Psr7\Response(Response::HTTP_INTERNAL_SERVER_ERROR, body: (string)$throwable));
                    } else {
                        $worker->respond(new Psr7\Response(Response::HTTP_INTERNAL_SERVER_ERROR));
                    }
                }

                $worker->getWorker()->error((string)$throwable);

                // hard errors stop workers
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
                    $worker->getWorker()->error("Fatal worker cleanup error: " . $cleanupThrowable);
                    $worker->getWorker()->stop();
                } finally {
                    try {
                        $this->servicesResetter?->reset();
                    } catch (\Throwable $throwable) {
                        $worker->getWorker()->error((string)$throwable);
                        $worker->getWorker()->stop();
                    }
                }

                try {
                    $this->sentryHubInterface?->getClient()?->flush();
                } catch (\Throwable) {}
                try {
                    $this->sentryHubInterface?->popScope();
                } catch (\Throwable) {}

                unset($request, $symfonyRequest, $symfonyResponse, $content);
            }
        }
    }
}