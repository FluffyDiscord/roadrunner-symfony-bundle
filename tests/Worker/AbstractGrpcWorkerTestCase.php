<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcFrameDecoder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcInvoker;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcResponseEncoder;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntimeFactory;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures\EchoService;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\GrpcWorker;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

/**
 * The gRPC worker resolves its per-boot collaborators through the kernel container
 * (GrpcWorkerRuntimeFactory), so the harness builds a real minimal Container holding a real
 * factory over a real EventDispatcher — only the RR worker, kernel and resetter are mocks.
 * The loop is driven through the waitPayload() seam with a queue of payloads.
 */
class TestableGrpcWorker extends GrpcWorker
{
    /** @var list<string> */
    public array $loggedErrors = [];
    public int $shutdownRegistrations = 0;
    public ?\Closure $registeredShutdown = null;
    /** @var list<Payload|null> */
    public array $payloadQueue = [];

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function registerShutdown(callable $handler): void
    {
        ++$this->shutdownRegistrations;
        $this->registeredShutdown = \Closure::fromCallable($handler);
    }

    protected function waitPayload(): ?Payload
    {
        if ($this->payloadQueue === []) {
            return null;
        }

        return array_shift($this->payloadQueue);
    }

    /**
     * @param array{message?: string, file?: string, line?: int}|null $error
     */
    public function callHandleShutdown(?array $error): void
    {
        $this->handleShutdown($error);
    }
}

abstract class AbstractGrpcWorkerTestCase extends BaseTestCase
{
    protected RrWorkerInterface&MockObject $rrWorker;
    /** @var (KernelInterface&RebootableInterface)&MockObject */
    protected KernelInterface&MockObject $kernel;
    protected ServicesResetterInterface&MockObject $servicesResetter;
    protected EventDispatcher $eventDispatcher;
    protected Container $kernelContainer;
    protected ?GrpcCallAuthenticatorInterface $authenticator = null;

    /** @var list<object> */
    protected array $dispatchedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rrWorker = $this->createMock(RrWorkerInterface::class);
        $this->kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, RebootableInterface::class]);
        $this->servicesResetter = $this->createMock(ServicesResetterInterface::class);
        $this->eventDispatcher = new EventDispatcher();
        $this->dispatchedEvents = [];
        $this->kernelContainer = new Container();

        $this->kernel->method('getContainer')->willReturn($this->kernelContainer);
        $this->registerRuntimeFactory($this->eventDispatcher);
    }

    /**
     * @param class-string<ServiceInterface> $interface
     */
    protected function registerRuntimeFactory(EventDispatcher $eventDispatcher, ?object $handler = null, string $interface = EchoInterface::class): void
    {
        $handler ??= new EchoService();
        $registry = new GrpcServiceRegistry(new ServiceLocator(['app.echo' => static fn (): object => $handler]));
        $registry->addService($interface, 'app.echo', $handler::class);

        $factory = new GrpcWorkerRuntimeFactory(
            $eventDispatcher,
            $registry,
            new GrpcInvoker($eventDispatcher),
            $this->authenticator,
            $this->servicesResetter,
        );

        $this->kernelContainer->set(GrpcWorkerRuntimeFactory::class, $factory);
    }

    protected function recordDispatchedEvents(): void
    {
        $recorder = function (object $event): void {
            $this->dispatchedEvents[] = $event;
        };

        foreach ([
            \FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent::class,
            \FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent::class,
            \FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent::class,
            \FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent::class,
            \FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent::class,
            \FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent::class,
        ] as $eventClass) {
            $this->eventDispatcher->addListener($eventClass, $recorder);
        }
    }

    /**
     * @param list<Payload|null> $payloads
     */
    protected function makeWorker(array $payloads = [], ?SentryHubInterface $sentryHub = null, bool $debug = false): TestableGrpcWorker
    {
        $worker = new TestableGrpcWorker(
            kernel: $this->kernel,
            rrWorker: $this->rrWorker,
            frameDecoder: new GrpcFrameDecoder(),
            responseEncoder: new GrpcResponseEncoder(),
            debug: $debug,
            sentryHubInterface: $sentryHub,
        );
        $worker->payloadQueue = $payloads;

        return $worker;
    }

    protected function makeFramePayload(string $service, string $method, string $body = '', array $metadata = []): Payload
    {
        $header = json_encode([
            'service' => $service,
            'method'  => $method,
            'context' => $metadata === [] ? new \stdClass() : $metadata,
        ], JSON_THROW_ON_ERROR);

        return new Payload($body, $header);
    }

    /**
     * @return list<class-string>
     */
    protected function dispatchedEventClasses(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->dispatchedEvents);
    }
}
