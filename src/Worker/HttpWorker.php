<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
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
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

readonly class HttpWorker implements WorkerInterface
{
    private HttpFoundationFactoryInterface $httpFoundationFactory;
    private Psr7\Factory\Psr17Factory $psrFactory;

    public function __construct(
        private bool                     $lazyBoot,
        private KernelInterface          $kernel,
        private EventDispatcherInterface $eventDispatcher,
        private ?SentryHubInterface      $sentryHubInterface = null,
        ?HttpFoundationFactoryInterface  $httpFoundationFactory = null,
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
            // Reduces first real request response time more than 50%.
            if ($_ENV["APP_ENV"] === "prod") {
                $this->kernel->handle(new Request());

                // Preload reflections, up to 2ms savings for each, YMMW
                new \ReflectionClass(StreamedJsonResponse::class);
                new \ReflectionClass(StreamedResponse::class);
                new \ReflectionClass(BinaryFileResponse::class);
            }
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

                    if ($this->kernel instanceof TerminableInterface) {
                        $this->kernel->terminate($symfonyRequest, $symfonyResponse);
                    }

                } catch (\Throwable $throwable) {
                    $this->sentryHubInterface?->captureException($throwable);
                    $worker->getWorker()->error((string)$throwable);

                } finally {
                    $this->sentryHubInterface?->getClient()?->flush()->wait(false);
                    $this->sentryHubInterface?->popScope();
                }
            }
        } catch (\Throwable $throwable) {
            $worker->getWorker()->stop();
            throw $throwable;
        }
    }
}
