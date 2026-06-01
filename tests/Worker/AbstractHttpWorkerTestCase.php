<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Http\HttpWorker as SpiralHttpWorker;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

interface TestKernelInterface extends KernelInterface, TerminableInterface, RebootableInterface
{
}

class TestableHttpWorker extends HttpWorker
{
    private PSR7Worker $injectedWorker;

    /** @var list<string> messages captured from logError() */
    public array $loggedErrors = [];
    /** number of times the shutdown handler was registered */
    public int $shutdownRegistrations = 0;
    /** the captured shutdown closure (not actually registered, to keep PHPUnit's process clean) */
    public ?\Closure $registeredShutdown = null;
    /** when true, renderHtmlError() throws, to exercise the MinimalErrorPage fallback */
    public bool $failHtmlRenderer = false;

    public function injectPsr7Worker(PSR7Worker $worker): void
    {
        $this->injectedWorker = $worker;
    }

    protected function createPsr7Worker(): PSR7Worker
    {
        return $this->injectedWorker;
    }

    protected function registerShutdown(callable $handler): void
    {
        ++$this->shutdownRegistrations;
        $this->registeredShutdown = \Closure::fromCallable($handler);
        // intentionally NOT calling register_shutdown_function() in tests
    }

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function renderHtmlError(\Throwable $throwable): \Symfony\Component\ErrorHandler\Exception\FlattenException
    {
        if ($this->failHtmlRenderer) {
            throw new \RuntimeException('simulated renderer failure');
        }

        return parent::renderHtmlError($throwable);
    }

    /** Invoke the protected Bucket B handler directly. */
    public function callHandleShutdown(PSR7Worker $worker, bool $handlingRequest, bool $responseStarted, ?array $error): void
    {
        $this->handleShutdown($worker, $handlingRequest, $responseStarted, $error);
    }

    /** Invoke the protected Bucket A responder directly. */
    public function callSendThrowableResponse(PSR7Worker $worker, \Throwable $throwable): void
    {
        $this->sendThrowableResponse($worker, $throwable);
    }
}

#[AllowMockObjectsWithoutExpectations]
abstract class AbstractHttpWorkerTestCase extends BaseTestCase
{
    protected KernelInterface&MockObject $kernel;
    protected EventDispatcherInterface&MockObject $eventDispatcher;
    protected ServicesResetterInterface&MockObject $servicesResetter;
    protected PSR7Worker&MockObject $psr7Worker;
    protected SpiralHttpWorker&MockObject $spiralHttpWorker;
    protected RrWorkerInterface&MockObject $rrWorker;
    protected HttpFoundationFactoryInterface&MockObject $httpFoundationFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kernel = $this->createMock(KernelInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->servicesResetter = $this->createMock(ServicesResetterInterface::class);
        $this->psr7Worker = $this->createMock(PSR7Worker::class);
        $this->spiralHttpWorker = $this->createMock(SpiralHttpWorker::class);
        $this->rrWorker = $this->createMock(RrWorkerInterface::class);
        $this->httpFoundationFactory = $this->createMock(HttpFoundationFactoryInterface::class);

        $this->psr7Worker->method('getHttpWorker')->willReturn($this->spiralHttpWorker);
        $this->psr7Worker->method('getWorker')->willReturn($this->rrWorker);
    }

    protected function makeWorker(
        bool                $earlyRouterInit = false,
        bool                $lazyBoot = true,
        bool                $debug = false,
        ?KernelInterface    $kernel = null,
        ?SentryHubInterface $sentryHub = null,
    ): TestableHttpWorker
    {
        $worker = new TestableHttpWorker(
            earlyRouterInitialization: $earlyRouterInit,
            lazyBoot: $lazyBoot,
            kernel: $kernel ?? $this->kernel,
            eventDispatcher: $this->eventDispatcher,
            debug: $debug,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: $sentryHub,
            httpFoundationFactory: $this->httpFoundationFactory,
        );
        $worker->injectPsr7Worker($this->psr7Worker);
        return $worker;
    }

    protected function makeSentryHubMock(): SentryHubInterface&MockObject
    {
        $hub = $this->createMock(SentryHubInterface::class);
        $hub->method('pushScope')->willReturn(new \Sentry\State\Scope());
        return $hub;
    }

    protected function psrRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', 'http://localhost/test');
    }

    protected function setupSuccessfulRequest(Response $response = new Response('ok', 200)): void
    {
        $this->psr7Worker->method('waitRequest')->willReturnOnConsecutiveCalls($this->psrRequest(), null);

        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willReturn($response);
    }

    protected static function makeOutOfMemoryError(): OutOfMemoryError
    {
        return new OutOfMemoryError(
            'Error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 1048576 bytes)',
            0,
            ['type' => \E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => __FILE__, 'line' => __LINE__],
        );
    }
}
