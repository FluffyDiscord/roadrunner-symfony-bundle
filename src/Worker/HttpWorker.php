<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Factory\BinaryFileResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\DefaultResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedJsonResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Factory\StreamedResponseWrapper;
use GuzzleHttp\Promise\PromiseInterface;
use Nyholm\Psr7;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

// Sentry v4 compatibility

class HttpWorker implements WorkerInterface
{
    private HttpFoundationFactoryInterface $httpFoundationFactory;
    private Psr7\Factory\Psr17Factory $psrFactory;

    public const DUMMY_REQUEST_ATTRIBUTE = "rr_dummy_request";

    public function __construct(
        private readonly bool                     $earlyRouterInitialization,
        private readonly bool                     $lazyBoot,
        private readonly KernelInterface          $kernel,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?SentryHubInterface      $sentryHubInterface = null,
        ?HttpFoundationFactoryInterface           $httpFoundationFactory = null,
    )
    {
        $this->psrFactory = new Psr7\Factory\Psr17Factory();
        $this->httpFoundationFactory = $httpFoundationFactory ?? new HttpFoundationFactory();
    }

    public function start(): void
    {
        $worker = new RoadRunner\Http\PSR7Worker(
            RoadRunner\Worker::create(),
            $this->psrFactory,
            $this->psrFactory,
            $this->psrFactory,
        );

        if (!$this->lazyBoot) {
            $this->kernel->boot();

            // Initialize routing and other lazy services that Symfony has.
            // Reduces first real request response time more than 50%, YMMW
            if ($this->earlyRouterInitialization) {
                $this->kernel->handle(new Request(attributes: [self::DUMMY_REQUEST_ATTRIBUTE => true]));
            }

            // Preload reflections, up to 2ms savings for each, YMMW
            if (Kernel::MAJOR_VERSION >= 6) {
                new \ReflectionClass(StreamedJsonResponse::class);
            }

            new \ReflectionClass(StreamedResponse::class);
            new \ReflectionClass(BinaryFileResponse::class);
        }

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        try {
            while ($request = $worker->waitRequest()) {
                $this->sentryHubInterface?->pushScope();

                try {
                    $symfonyRequest = $this->httpFoundationFactory->createRequest($request);
                    $symfonyResponse = $this->kernel->handle($symfonyRequest);

                    $content = match (true) {
                        $symfonyResponse instanceof StreamedJsonResponse => StreamedJsonResponseWrapper::wrap($symfonyResponse),
                        $symfonyResponse instanceof StreamedResponse => StreamedResponseWrapper::wrap($symfonyResponse),
                        $symfonyResponse instanceof BinaryFileResponse => BinaryFileResponseWrapper::wrap($symfonyResponse, $symfonyRequest),
                        default => DefaultResponseWrapper::wrap($symfonyResponse),
                    };

                    $worker->getHttpWorker()->respond(
                        $symfonyResponse->getStatusCode(),
                        $content,
                        $symfonyResponse->headers->all(),
                    );

                    $this->eventDispatcher->dispatch(new WorkerResponseSentEvent());

                    if ($this->kernel instanceof TerminableInterface) {
                        $this->kernel->terminate($symfonyRequest, $symfonyResponse);
                    }

                } catch (\Throwable $throwable) {
                    $this->sentryHubInterface?->captureException($throwable);
                    $worker->getWorker()->error((string)$throwable);

                } finally {
                    $result = $this->sentryHubInterface?->getClient()?->flush();

                    // sentry v4 compatibility
                    if ($result instanceof PromiseInterface) {
                        $result->wait(false);
                    }

                    $this->sentryHubInterface?->popScope();
                }
            }
        } catch (\Throwable $throwable) {
            $worker->getWorker()->stop();
            throw $throwable;
        }
    }
}
