<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Factory\NativeSymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\SymfonyRequestFactoryInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Http\HttpWorker as SpiralHttpWorker;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->kernel = $this->createMock(KernelInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->servicesResetter = $this->createMock(ServicesResetterInterface::class);
        $this->psr7Worker = $this->createMock(PSR7Worker::class);
        $this->spiralHttpWorker = $this->createMock(SpiralHttpWorker::class);
        $this->rrWorker = $this->createMock(RrWorkerInterface::class);

        $this->psr7Worker->method('getHttpWorker')->willReturn($this->spiralHttpWorker);
        $this->psr7Worker->method('getWorker')->willReturn($this->rrWorker);
    }

    protected function makeWorker(
        bool                            $lazyBoot = true,
        bool                            $debug = false,
        ?KernelInterface                $kernel = null,
        ?SentryHubInterface             $sentryHub = null,
        ?SymfonyRequestFactoryInterface $symfonyRequestFactory = null,
        ?HttpFoundationFactoryInterface $httpFoundationFactory = null,
    ): TestableHttpWorker
    {
        $worker = new TestableHttpWorker(
            lazyBoot: $lazyBoot,
            kernel: $kernel ?? $this->kernel,
            eventDispatcher: $this->eventDispatcher,
            debug: $debug,
            servicesResetter: $this->servicesResetter,
            sentryHubInterface: $sentryHub,
            httpFoundationFactory: $httpFoundationFactory,
            symfonyRequestFactory: $symfonyRequestFactory ?? new NativeSymfonyRequestFactory(),
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

    protected function rrRequest(): RoadRunnerRequest
    {
        return new RoadRunnerRequest(
            method: 'GET',
            uri: 'http://localhost/test',
            headers: ['Host' => ['localhost']],
            attributes: [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => false],
        );
    }

    protected function setupSuccessfulRequest(Response $response = new Response('ok', 200)): void
    {
        $this->spiralHttpWorker->method('waitRequest')->willReturnOnConsecutiveCalls($this->rrRequest(), null);

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
