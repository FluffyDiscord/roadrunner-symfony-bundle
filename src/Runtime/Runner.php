<?php

namespace FluffyDiscord\RoadRunnerBundle\Runtime;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\BootFailureReporting;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\WorkerErrorResponder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcResponseEncoder;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Nyholm\Psr7;
use Sentry\SentrySdk;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

class Runner implements RunnerInterface
{
    use BootFailureReporting;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly string          $mode,
        private readonly string          $runtimeMode,
    )
    {
    }

    public function run(): int
    {
        $_SERVER['APP_RUNTIME_MODE'] = $this->runtimeMode;

        try {
            $this->kernel->boot();

            $registry = $this->kernel->getContainer()->get(WorkerRegistry::class);
            assert($registry instanceof WorkerRegistry);

            $worker = $registry->getWorker($this->mode);
        } catch (\Throwable $bootThrowable) {
            return $this->handleBootFailure($bootThrowable);
        }

        if (null === $worker) {
            $this->logError(sprintf('This bundle does not support worker "%s" yet, open issue or make PR', $this->mode));

            return 1;
        }

        $worker->start();

        return 0;
    }

    protected function handleBootFailure(\Throwable $throwable): int
    {
        $this->reportBootFailure($throwable);

        if ($this->mode === Mode::MODE_GRPC) {
            return $this->serveGrpcBootFailure($throwable);
        }

        if ($this->mode !== Mode::MODE_HTTP) {
            return 1;
        }

        try {
            $worker = $this->createFallbackPsr7Worker();
            $bootFailureRequest = $worker->getHttpWorker()->waitRequest();
        } catch (\Throwable) {
            return 1;
        }

        if ($bootFailureRequest === null) {
            return 1;
        }

        new WorkerErrorResponder($this->kernel->isDebug())->sendThrowableResponse($worker, $throwable);

        return 1;
    }

    protected function serveGrpcBootFailure(\Throwable $throwable): int
    {
        $grpcPackageInstalled = class_exists(StatusCode::class);

        if (!$grpcPackageInstalled) {
            return 1;
        }

        try {
            $worker = $this->createFallbackRoadRunnerWorker();
            $bootFailurePayload = $worker->waitPayload();
        } catch (\Throwable) {
            return 1;
        }

        if ($bootFailurePayload === null) {
            return 1;
        }

        $message = $this->kernel->isDebug() ? 'Worker boot failed: ' . $throwable : 'Worker boot failed';

        try {
            $worker->respond(new RoadRunner\Payload('', new GrpcResponseEncoder()->encodeStatus(StatusCode::UNAVAILABLE, $message)));
        } catch (\Throwable) {
        }

        return 1;
    }

    protected function createFallbackRoadRunnerWorker(): RoadRunner\WorkerInterface
    {
        return RoadRunner\Worker::create();
    }

    protected function getBootFailureLabel(): string
    {
        return sprintf('BOOT FAILURE (mode=%s)', $this->mode);
    }

    /**
     * The Sentry bundle registers its hub as a private service, so the container cannot expose it;
     * the SDK's global hub is the only reachable one once boot has failed.
     */
    protected function getBootFailureSentryHub(): ?SentryHubInterface
    {
        if (!class_exists(SentrySdk::class)) {
            return null;
        }

        return SentrySdk::getCurrentHub();
    }

    protected function createFallbackPsr7Worker(): RoadRunner\Http\PSR7Worker
    {
        $psrFactory = new Psr7\Factory\Psr17Factory();

        return new RoadRunner\Http\PSR7Worker(
            RoadRunner\Worker::create(),
            $psrFactory,
            $psrFactory,
            $psrFactory,
        );
    }
}
