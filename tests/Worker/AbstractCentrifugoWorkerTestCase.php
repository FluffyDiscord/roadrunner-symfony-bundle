<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use PHPUnit\Framework\MockObject\MockObject;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Request;
use RoadRunner\Centrifugo\Request\RequestFactory;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The real RoadRunner\Centrifugo\CentrifugoWorker and most Request\* classes are `final`, so they
 * cannot be mocked. Instead we build a real RoadRunnerCentrifugoWorker around a mocked goridge
 * WorkerInterface (its getWorker() returns that mock, so error()/stop() are observable), feed the
 * loop through a waitRequest() seam, and construct real Request\* fixtures.
 */
class TestableCentrifugoWorker extends CentrifugoWorker
{
    /** @var list<string> */
    public array $loggedErrors = [];
    public int $shutdownRegistrations = 0;
    public ?\Closure $registeredShutdown = null;
    /** @var list<Request\RequestInterface> */
    public array $requestQueue = [];

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function registerShutdown(callable $handler): void
    {
        ++$this->shutdownRegistrations;
        $this->registeredShutdown = \Closure::fromCallable($handler);
    }

    protected function waitRequest(): ?Request\RequestInterface
    {
        return array_shift($this->requestQueue);
    }

    public function callHandleShutdown(bool $handlingRequest, bool $responded, ?Request\RequestInterface $request, ?array $error): void
    {
        $this->handleShutdown($handlingRequest, $responded, $request, $error);
    }

    public function callChooseFailureAction(Request\RequestInterface $request): string
    {
        return $this->chooseFailureAction($request);
    }

    public function callClientMessage(\Throwable $throwable): string
    {
        return $this->clientMessage($throwable);
    }
}

abstract class AbstractCentrifugoWorkerTestCase extends BaseTestCase
{
    protected RrWorkerInterface&MockObject $goridgeWorker;
    protected RoadRunnerCentrifugoWorker $centrifugoWorker;
    protected KernelInterface&MockObject $kernel;
    protected EventDispatcherInterface&MockObject $eventDispatcher;
    protected ServicesResetterInterface&MockObject $servicesResetter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->goridgeWorker = $this->createMock(RrWorkerInterface::class);
        $this->centrifugoWorker = new RoadRunnerCentrifugoWorker($this->goridgeWorker, new RequestFactory($this->goridgeWorker));
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->servicesResetter = $this->createMock(ServicesResetterInterface::class);
    }

    /**
     * @param list<Request\RequestInterface> $requests
     */
    protected function makeWorker(bool $debug = false, array $requests = [], ?SentryHubInterface $sentryHub = null): TestableCentrifugoWorker
    {
        $worker = new TestableCentrifugoWorker(
            lazyBoot: true,
            debug: $debug,
            kernel: $this->kernel,
            worker: $this->centrifugoWorker,
            eventDispatcher: $this->eventDispatcher,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: $sentryHub,
        );
        $worker->requestQueue = $requests;

        return $worker;
    }

    // --- real Request\* fixtures (final classes; constructed with a mocked goridge worker) ---

    protected function makeConnect(): Request\Connect
    {
        return new Request\Connect($this->goridgeWorker, 'client', 'transport', 'json', 'json', [], null, null, [], []);
    }

    protected function makeSubscribe(): Request\Subscribe
    {
        return new Request\Subscribe($this->goridgeWorker, 'client', 'transport', 'json', 'json', 'user', 'channel', 'token', [], [], []);
    }

    protected function makeRpc(): Request\RPC
    {
        return new Request\RPC($this->goridgeWorker, 'client', 'transport', 'json', 'json', 'user', 'method', [], [], []);
    }

    protected function makePublish(): Request\Publish
    {
        return new Request\Publish($this->goridgeWorker, 'client', 'transport', 'json', 'json', 'user', 'channel', [], [], []);
    }

    protected function makeRefresh(): Request\Refresh
    {
        return new Request\Refresh($this->goridgeWorker, 'client', 'transport', 'json', 'json', 'user', [], []);
    }

    protected function makeSubRefresh(): Request\SubRefresh
    {
        return new Request\SubRefresh($this->goridgeWorker, 'client', 'transport', 'json', 'json', 'user', 'channel', [], []);
    }

    protected function makeInvalid(): Request\Invalid
    {
        return new Request\Invalid(new \RuntimeException('invalid request'));
    }
}
