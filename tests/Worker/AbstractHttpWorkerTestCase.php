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
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
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

    public function injectPsr7Worker(PSR7Worker $worker): void
    {
        $this->injectedWorker = $worker;
    }

    protected function createPsr7Worker(): PSR7Worker
    {
        return $this->injectedWorker;
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
