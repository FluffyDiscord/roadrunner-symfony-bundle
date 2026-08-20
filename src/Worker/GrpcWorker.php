<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\BootFailureReporting;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcFrameDecodingException;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcHandlerFaultException;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcSecurityConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcFrame;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcFrameDecoder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcInvoker;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcResponseEncoder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntime;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntimeFactory;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\Exception\NotFoundException;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

class GrpcWorker implements WorkerInterface
{
    use BootFailureReporting;

    private bool $shutdownRegistered = false;
    private bool $handlingFrame = false;
    private bool $responded = false;
    private ?GrpcFrame $currentFrame = null;
    private ?GrpcWorkerRuntime $runtime = null;

    public function __construct(
        private readonly KernelInterface     $kernel,
        private readonly RrWorkerInterface   $rrWorker,
        private readonly GrpcFrameDecoder    $frameDecoder,
        private readonly GrpcResponseEncoder $responseEncoder,
        private readonly bool                $debug,
        private readonly ?SentryHubInterface $sentryHubInterface = null,
    )
    {
    }

    public function start(): void
    {
        try {
            $this->kernel->boot();
            $this->runtime = $this->buildRuntime();
        } catch (\Throwable $bootThrowable) {
            $this->reportBootFailure($bootThrowable);
            $this->serveBootFailure($bootThrowable);

            return;
        }

        $this->dispatchBootingEvent();
        $this->registerShutdownOnce();
        $this->serve();
    }

    private function serve(): void
    {
        while (true) {
            $payload = $this->waitPayload();

            if ($payload === null) {
                return;
            }

            $this->handleFrame($payload);
        }
    }

    private function handleFrame(Payload $payload): void
    {
        $runtime = $this->getRuntime();
        $this->handlingFrame = true;
        $this->responded = false;
        $this->currentFrame = null;
        $routed = false;
        $hadUnhandledThrowable = false;
        $startedAt = hrtime(true);
        $responseHeaders = new ResponseHeaders();
        $responseTrailers = new ResponseTrailers();
        $context = null;

        try {
            $this->kernel->boot();
            $this->sentryHubInterface?->pushScope();
            $runtime->eventDispatcher->dispatch(new WorkerRequestReceivedEvent());

            $frame = $this->frameDecoder->decode($payload->header);
            $this->currentFrame = $frame;
            $metadata = new GrpcMetadata($frame->metadata);
            $context = $this->buildContext($frame, $metadata, $responseHeaders, $responseTrailers);

            $route = $runtime->routingTable->getRoute($frame->serviceName);
            if ($route === null) {
                throw NotFoundException::create(sprintf('Service `%s` not found.', $frame->serviceName), StatusCode::NOT_FOUND);
            }

            $methodRoute = $route->getMethod($frame->methodName);
            if ($methodRoute === null) {
                throw NotFoundException::create(sprintf('Method `%s` not found in service `%s`.', $frame->methodName, $frame->serviceName), StatusCode::NOT_FOUND);
            }

            $runtime->authenticator?->authenticate($metadata);

            $routed = true;
            $body = $runtime->invoker->invoke($route, $methodRoute, $context, $payload->body);

            $this->answer(new Payload($body, $this->responseEncoder->encodeSuccessHeaders($responseHeaders, $responseTrailers)));
        } catch (GrpcFrameDecodingException $decodingException) {
            $this->dispatchFailedEvent($decodingException, StatusCode::INVALID_ARGUMENT, null, $startedAt);
            $this->captureException($decodingException);
            $this->logError((string)$decodingException);
            $this->answer(new Payload('', $this->responseEncoder->encodeStatus(StatusCode::INVALID_ARGUMENT, 'Malformed gRPC frame')));
        } catch (GRPCExceptionInterface $grpcException) {
            if (!$routed) {
                $this->dispatchFailedEvent($grpcException, $grpcException->getCode(), $context, $startedAt);
            }

            $hadUnhandledThrowable = $this->reportGrpcExceptionIfServerFault($grpcException);

            $this->answer(new Payload('', $this->responseEncoder->encodeError($grpcException, $responseHeaders, $responseTrailers)));
        } catch (\Throwable $throwable) {
            $hadUnhandledThrowable = true;

            if (!$routed) {
                $this->dispatchFailedEvent($throwable, StatusCode::UNKNOWN, $context, $startedAt);
            }

            $this->captureException($throwable);
            $this->answerError($this->debug ? (string)$throwable : $throwable->getMessage());
            $this->logError((string)$throwable);

            if ($throwable instanceof \Error) {
                $this->rrWorker->stop();
            }
        } finally {
            $this->finishFrame($hadUnhandledThrowable);
        }
    }

    private function finishFrame(bool $hadUnhandledThrowable): void
    {
        $this->resetServices();

        if ($hadUnhandledThrowable) {
            $this->rebootKernel();
        }

        $this->flushSentryScope();

        $this->handlingFrame = false;
        $this->currentFrame = null;
    }

    private function resetServices(): void
    {
        try {
            $this->getRuntime()->servicesResetter?->reset();
        } catch (\Throwable $resetThrowable) {
            $this->logError((string)$resetThrowable);
            $this->rrWorker->stop();
        }
    }

    private function rebootKernel(): void
    {
        if (!$this->kernel instanceof RebootableInterface) {
            return;
        }

        try {
            $this->kernel->reboot(null);
            $this->runtime = $this->buildRuntime();
        } catch (\Throwable $cleanupThrowable) {
            $this->logError('Fatal worker cleanup error: ' . $cleanupThrowable);
            $this->rrWorker->stop();
        }
    }

    private function flushSentryScope(): void
    {
        try {
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {
        }

        try {
            $this->sentryHubInterface?->popScope();
        } catch (\Throwable) {
        }
    }

    private function buildContext(GrpcFrame $frame, GrpcMetadata $metadata, ResponseHeaders $responseHeaders, ResponseTrailers $responseTrailers): ContextInterface
    {
        $bundleEntries = [
            ResponseHeaders::class  => $responseHeaders,
            ResponseTrailers::class => $responseTrailers,
            GrpcMetadata::class     => $metadata,
        ];

        return new Context($bundleEntries + $frame->metadata);
    }

    private function reportGrpcExceptionIfServerFault(GRPCExceptionInterface $grpcException): bool
    {
        if ($grpcException instanceof GrpcHandlerFaultException) {
            $this->captureException($grpcException);
            $this->logError((string)$grpcException);

            return true;
        }

        if ($grpcException instanceof GrpcSecurityConfigurationException) {
            $this->captureException($grpcException);
            $this->logError((string)$grpcException);
        }

        return false;
    }

    private function dispatchFailedEvent(\Throwable $throwable, int $workerStatusCode, ?ContextInterface $context, int|float $startedAt): void
    {
        $durationMs = round((hrtime(true) - $startedAt) / 1e6, 3);
        $serviceName = $this->currentFrame?->serviceName ?? '';
        $methodName = $this->currentFrame?->methodName ?? '';

        try {
            $this->getRuntime()->eventDispatcher->dispatch(new GrpcCallFailedEvent($serviceName, $methodName, $context, null, $throwable, $workerStatusCode, $durationMs));
        } catch (\Throwable $listenerThrowable) {
            $this->logError('gRPC failed-event listener threw: ' . $listenerThrowable);
        }
    }

    private function answer(Payload $payload): void
    {
        if ($this->responded) {
            $this->logError('Frame already answered');

            return;
        }

        try {
            $this->rrWorker->respond($payload);
        } catch (\Throwable $relayThrowable) {
            $this->logError('Failed to answer gRPC frame: ' . $relayThrowable);
            $this->rrWorker->stop();

            return;
        }

        $this->responded = true;
        $this->dispatchResponseSent();
    }

    private function answerError(string $message): void
    {
        if ($this->responded) {
            $this->logError('Frame already answered');

            return;
        }

        try {
            $this->rrWorker->error($message);
        } catch (\Throwable $relayThrowable) {
            $this->logError('Failed to answer gRPC frame: ' . $relayThrowable);
            $this->rrWorker->stop();

            return;
        }

        $this->responded = true;
        $this->dispatchResponseSent();
    }

    private function dispatchResponseSent(): void
    {
        try {
            $this->getRuntime()->eventDispatcher->dispatch(new WorkerResponseSentEvent(Mode::MODE_GRPC));
        } catch (\Throwable $listenerThrowable) {
            $this->logError('WorkerResponseSentEvent listener threw: ' . $listenerThrowable);
        }
    }

    private function captureException(\Throwable $throwable): void
    {
        try {
            $this->sentryHubInterface?->captureException($throwable);
        } catch (\Throwable) {
        }
    }

    private function dispatchBootingEvent(): void
    {
        try {
            $this->getRuntime()->eventDispatcher->dispatch(new WorkerBootingEvent());
        } catch (\Throwable $listenerThrowable) {
            $this->reportBootFailure($listenerThrowable);
        }
    }

    private function registerShutdownOnce(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        $this->registerShutdown(function (): void {
            $this->handleShutdown(error_get_last());
        });
    }

    private function buildRuntime(): GrpcWorkerRuntime
    {
        $factory = $this->kernel->getContainer()->get(GrpcWorkerRuntimeFactory::class);

        if (!$factory instanceof GrpcWorkerRuntimeFactory) {
            throw new \LogicException(sprintf('Service %s is not available in the container', GrpcWorkerRuntimeFactory::class));
        }

        return $factory->create();
    }

    private function getRuntime(): GrpcWorkerRuntime
    {
        if ($this->runtime === null) {
            throw new \LogicException('gRPC worker runtime is not built; start() was not called');
        }

        return $this->runtime;
    }

    protected function serveBootFailure(\Throwable $bootThrowable): void
    {
        try {
            $payload = $this->waitPayload();
        } catch (\Throwable) {
            return;
        }

        if ($payload === null) {
            return;
        }

        try {
            $this->rrWorker->respond(new Payload('', $this->responseEncoder->encodeStatus(StatusCode::UNAVAILABLE, $this->describeBootFailure($bootThrowable))));
        } catch (\Throwable) {
        }
    }

    private function describeBootFailure(\Throwable $bootThrowable): string
    {
        if ($this->debug) {
            return 'Worker boot failed: ' . $bootThrowable;
        }

        return 'Worker boot failed';
    }

    /**
     * @param array{message?: string, file?: string, line?: int}|null $error
     */
    protected function handleShutdown(?array $error): void
    {
        if (!$this->handlingFrame || $this->responded) {
            return;
        }

        $fatalMessage = $error['message'] ?? null;
        $isMemoryExhaustion = $fatalMessage !== null && str_contains($fatalMessage, 'Allowed memory size');

        if ($isMemoryExhaustion) {
            @ini_set('memory_limit', '-1');
        }

        $callLabel = ($this->currentFrame?->serviceName ?? 'unknown') . '/' . ($this->currentFrame?->methodName ?? 'unknown');
        $reason = $fatalMessage ?? 'die/exit';

        try {
            $this->rrWorker->error(sprintf('Worker terminated during gRPC call %s: %s', $callLabel, $reason));
        } catch (\Throwable) {
        }

        $this->logError(
            $fatalMessage !== null
                ? sprintf('fatal: %s in %s:%d', $fatalMessage, $error['file'] ?? '?', $error['line'] ?? 0)
                : 'worker terminated via die/exit during gRPC call',
        );

        try {
            $this->sentryHubInterface?->captureMessage('RoadRunner gRPC worker fatal: ' . $reason);
            $this->sentryHubInterface?->getClient()?->flush();
        } catch (\Throwable) {
        }
    }

    protected function waitPayload(): ?Payload
    {
        return $this->rrWorker->waitPayload();
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
