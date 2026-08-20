<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Runtime;

use FluffyDiscord\RoadRunnerBundle\Runtime\Runner;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerInterface;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentrySdk;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\Http\HttpWorker as SpiralHttpWorker;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

class TestableRunner extends Runner
{
    /** @var list<string> messages captured from logError() */
    public array $loggedErrors = [];
    public int $fallbackWorkerCreations = 0;

    public ?PSR7Worker $injectedFallbackWorker = null;

    protected function createFallbackPsr7Worker(): PSR7Worker
    {
        ++$this->fallbackWorkerCreations;

        if ($this->injectedFallbackWorker === null) {
            throw new \LogicException('no fallback worker injected');
        }

        return $this->injectedFallbackWorker;
    }

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }
}

/**
 * Bucket D1 — the kernel, the registry or the worker construction dies inside Runner::run().
 *
 * @see docs/specs/graceful-error-handling.md §6.4, §6.9 (TC-D07..TC-D09, TC-D11)
 */
#[AllowMockObjectsWithoutExpectations]
class RunnerBootFailureTest extends BaseTestCase
{
    private KernelInterface&MockObject $kernel;
    private ContainerInterface&MockObject $container;
    private PSR7Worker&MockObject $psr7Worker;
    private SpiralHttpWorker&MockObject $spiralHttpWorker;
    private RrWorkerInterface&MockObject $rrWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kernel = $this->createMock(KernelInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->psr7Worker = $this->createMock(PSR7Worker::class);
        $this->spiralHttpWorker = $this->createMock(SpiralHttpWorker::class);
        $this->rrWorker = $this->createMock(RrWorkerInterface::class);

        $this->kernel->method('getContainer')->willReturn($this->container);
        $this->psr7Worker->method('getHttpWorker')->willReturn($this->spiralHttpWorker);
        $this->psr7Worker->method('getWorker')->willReturn($this->rrWorker);
        $this->container->method('has')->willReturn(false);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_RUNTIME_MODE']);

        parent::tearDown();
    }

    private function makeRunner(string $mode = Mode::MODE_HTTP): TestableRunner
    {
        $runner = new TestableRunner($this->kernel, $mode, 'web=1&worker=1');
        $runner->injectedFallbackWorker = $this->psr7Worker;

        return $runner;
    }

    private function rrRequest(): RoadRunnerRequest
    {
        return new RoadRunnerRequest(
            method: 'GET',
            uri: 'http://localhost/test',
            headers: ['Host' => ['localhost']],
            attributes: [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => false],
        );
    }

    /** TC-D07 */
    public function testHttpBootFailureAnswersOneRequestWithTheDebugPage(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('container is broken'));
        $this->kernel->method('isDebug')->willReturn(true);
        $this->spiralHttpWorker->expects($this->once())->method('waitRequest')->willReturn($this->rrRequest());

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && str_contains((string)$response->getBody(), 'container is broken'),
            ))
        ;
        $this->rrWorker->expects($this->never())->method('stop');
        $this->rrWorker->expects($this->never())->method('error');

        $runner = $this->makeRunner();

        $this->assertSame(1, $runner->run());
        $this->assertSame(1, $runner->fallbackWorkerCreations);
        $this->assertStringContainsString('BOOT FAILURE (mode=http)', implode("\n", $runner->loggedErrors));
    }

    /** TC-D07: prod boot failure discloses nothing. */
    public function testHttpBootFailureInProductionAnswersWithAnEmptyBody(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('container is broken'));
        $this->kernel->method('isDebug')->willReturn(false);
        $this->spiralHttpWorker->method('waitRequest')->willReturn($this->rrRequest());

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && (string)$response->getBody() === '',
            ))
        ;

        $this->assertSame(1, $this->makeRunner()->run());
    }

    /** TC-D07 edge: no request ever arrives → no frame. */
    public function testHttpBootFailureSendsNoFrameWhenNoRequestArrives(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('container is broken'));
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);
        $this->psr7Worker->expects($this->never())->method('respond');

        $this->assertSame(1, $this->makeRunner()->run());
    }

    /** TC-D07 edge: waitRequest() itself throwing must not escape. */
    public function testHttpBootFailureSurvivesWaitRequestThrowing(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('container is broken'));
        $this->spiralHttpWorker->method('waitRequest')->willThrowException(new \RuntimeException('relay is gone'));
        $this->psr7Worker->expects($this->never())->method('respond');

        $this->assertSame(1, $this->makeRunner()->run());
    }

    /** TC-D08 */
    public function testNonHttpBootFailureOnlyLogs(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('container is broken'));

        $runner = $this->makeRunner(Mode::MODE_JOBS);

        $this->assertSame(1, $runner->run());
        $this->assertSame(0, $runner->fallbackWorkerCreations, 'non-HTTP modes have no client to answer');
        $this->assertStringContainsString('BOOT FAILURE (mode=jobs)', implode("\n", $runner->loggedErrors));
    }

    /** TC-D09: the boundary covers more than boot() — worker construction counts too. */
    public function testWorkerConstructionFailureIsAlsoABootFailure(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('centrifugo worker cannot connect'));
        $this->kernel->method('isDebug')->willReturn(true);
        $this->spiralHttpWorker->method('waitRequest')->willReturn($this->rrRequest());

        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => str_contains((string)$response->getBody(), 'centrifugo worker cannot connect'),
            ))
        ;

        $runner = $this->makeRunner();

        $this->assertSame(1, $runner->run());
        $this->assertSame(1, $runner->fallbackWorkerCreations);
    }

    /** Invariant D-2: the boundary must not swallow request-loop failures. */
    public function testRequestLoopFailuresAreNotTreatedAsBootFailures(): void
    {
        $worker = $this->createMock(WorkerInterface::class);
        $worker->method('start')->willThrowException(new \RuntimeException('relay died mid-loop'));

        $registry = new WorkerRegistry();
        $registry->registerWorker(Mode::MODE_HTTP, $worker);
        $this->container->method('get')->willReturn($registry);

        $runner = $this->makeRunner();

        try {
            $runner->run();
            $this->fail('a request-loop failure must propagate, not be caught as a boot failure');
        } catch (\RuntimeException $throwable) {
            $this->assertSame('relay died mid-loop', $throwable->getMessage());
        }

        $this->assertSame(0, $runner->fallbackWorkerCreations);
        $this->assertSame([], $runner->loggedErrors);
    }

    /** TC-D09: the Sentry leg — the SDK hub is the only one reachable once boot failed. */
    public function testBootFailureIsCapturedByTheSdkHub(): void
    {
        $this->kernel->method('boot')->willThrowException(new \RuntimeException('container is broken'));
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);

        $sentryHub = $this->createMock(SentryHubInterface::class);
        $sentryHub->expects($this->once())->method('captureException');

        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub($sentryHub);

        try {
            $this->assertSame(1, $this->makeRunner()->run());
        } finally {
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    /** TC-D11 */
    public function testHealthyBootStartsTheWorker(): void
    {
        $worker = $this->createMock(WorkerInterface::class);
        $worker->expects($this->once())->method('start');

        $registry = new WorkerRegistry();
        $registry->registerWorker(Mode::MODE_HTTP, $worker);
        $this->container->method('get')->willReturn($registry);

        $runner = $this->makeRunner();

        $this->assertSame(0, $runner->run());
        $this->assertSame(0, $runner->fallbackWorkerCreations);
        $this->assertSame([], $runner->loggedErrors);
    }

    /** TC-D11: an unsupported mode still reports through the prefixed STDERR sink. */
    public function testUnsupportedModeLogsThroughLogError(): void
    {
        $this->container->method('get')->willReturn(new WorkerRegistry());

        $runner = $this->makeRunner('unsupported-mode');

        $this->assertSame(1, $runner->run());
        $this->assertStringContainsString('does not support worker "unsupported-mode"', implode("\n", $runner->loggedErrors));
    }
}
