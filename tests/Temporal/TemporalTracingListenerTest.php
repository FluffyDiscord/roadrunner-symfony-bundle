<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Exception\TemporalAddressException;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\SignalWithStartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\StartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Tracing\TemporalTracingListener;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Temporal\Client\WorkflowOptions;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowOutboundCalls\ExecuteActivityInput;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;

/**
 * TC-D13 / TC-D14 — the opt-in tracing listener and its conditional wiring.
 */
class TemporalTracingListenerTest extends BaseTestCase
{
    private function startEvent(): StartEvent
    {
        return new StartEvent(new StartInput(
            'wf-id',
            'MyWorkflow',
            Header::empty(),
            EncodedValues::empty(),
            WorkflowOptions::new(),
        ));
    }

    public function testPropagatesRequestIdAsCorrelationHeader(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->headers->set('X-Request-Id', 'req-123');
        $requestStack->push($request);

        $event = $this->startEvent();
        (new TemporalTracingListener(null, $requestStack, null))->onWorkflowStart($event);

        self::assertSame('req-123', $event->getInput()->header->getValue(TemporalTracingListener::CORRELATION_HEADER));
    }

    public function testPropagatesRequestIdIntoTheWorkflowStartedBySignalWithStart(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->headers->set('X-Request-Id', 'req-456');
        $requestStack->push($request);

        $event = new SignalWithStartEvent(new SignalWithStartInput(
            $this->startEvent()->getInput(),
            'changes',
            EncodedValues::empty(),
        ));
        (new TemporalTracingListener(null, $requestStack, null))->onWorkflowSignalWithStart($event);

        $header = $event->getInput()->workflowStartInput->header;
        self::assertSame('req-456', $header->getValue(TemporalTracingListener::CORRELATION_HEADER));
        self::assertSame('changes', $event->getInput()->signalName);
    }

    public function testGeneratesCorrelationWhenNoRequest(): void
    {
        $event = $this->startEvent();
        (new TemporalTracingListener(null, null, null))->onWorkflowStart($event);

        $correlation = $event->getInput()->header->getValue(TemporalTracingListener::CORRELATION_HEADER);
        self::assertIsString($correlation);
        self::assertNotSame('', $correlation);
    }

    public function testActivityListenersDoNotThrowWithoutDependencies(): void
    {
        $listener = new TemporalTracingListener(null, null, null);

        $listener->onExecuteActivity(new ExecuteActivityEvent(new ExecuteActivityInput('greeting.greet', [], null, null)));
        $listener->onActivityInbound(new ActivityEvent(new ActivityInput(EncodedValues::empty(), Header::empty())));

        $this->expectNotToPerformAssertions();
    }

    public function testExecuteActivityLogsThroughTheReplayAwareWorkflowLogger(): void
    {
        $workflowLogger = $this->createMock(LoggerInterface::class);
        $workflowLogger->expects(self::once())->method('debug')->with('Temporal: executing activity');

        $injectedLogger = $this->createMock(LoggerInterface::class);
        $injectedLogger->expects(self::never())->method('debug');

        $this->enterWorkflowContext($workflowLogger, false);

        (new TemporalTracingListener($injectedLogger, null, null))->onExecuteActivity($this->executeActivityEvent());
    }

    public function testExecuteActivitySkipsTheBreadcrumbWhileReplaying(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('addBreadcrumb');

        $this->enterWorkflowContext($this->createStub(LoggerInterface::class), true);

        (new TemporalTracingListener(null, null, $hub))->onExecuteActivity($this->executeActivityEvent());
    }

    public function testExecuteActivityAddsTheBreadcrumbOnFirstExecution(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('addBreadcrumb');

        $this->enterWorkflowContext($this->createStub(LoggerInterface::class), false);

        (new TemporalTracingListener(null, null, $hub))->onExecuteActivity($this->executeActivityEvent());
    }

    protected function tearDown(): void
    {
        Workflow::setCurrentContext(null);

        parent::tearDown();
    }

    private function enterWorkflowContext(LoggerInterface $workflowLogger, bool $isReplaying): void
    {
        $context = $this->createStub(WorkflowContextInterface::class);
        $context->method('getLogger')->willReturn($workflowLogger);
        $context->method('isReplaying')->willReturn($isReplaying);

        Workflow::setCurrentContext($context);
    }

    private function executeActivityEvent(): ExecuteActivityEvent
    {
        return new ExecuteActivityEvent(new ExecuteActivityInput('greeting.greet', [], null, null));
    }

    public function testListenerIsRegisteredOnlyWhenTracingEnabled(): void
    {
        self::assertFalse($this->loadWithTracing(false)->hasDefinition(TemporalTracingListener::class));

        $container = $this->loadWithTracing(true);
        self::assertTrue($container->hasDefinition(TemporalTracingListener::class));

        $events = array_map(
            static fn (array $tag) => $tag['event'],
            $container->getDefinition(TemporalTracingListener::class)->getTag('kernel.event_listener'),
        );
        self::assertContains(StartEvent::class, $events);
        self::assertContains(SignalWithStartEvent::class, $events);
        self::assertContains(ExecuteActivityEvent::class, $events);
        self::assertContains(ActivityEvent::class, $events);
    }

    public function testThrowsWhenTemporalAddressCannotBeResolved(): void
    {
        // No RoadRunner running (RR_RPC unset) and a non-existent .rr.yaml: the address is
        // unresolvable, which must surface as a build error rather than a localhost default.
        $originalEnv = $_ENV['RR_RPC'] ?? null;
        $originalServer = $_SERVER['RR_RPC'] ?? null;
        unset($_ENV['RR_RPC'], $_SERVER['RR_RPC']);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');

        try {
            $this->expectException(TemporalAddressException::class);

            (new FluffyDiscordRoadRunnerExtension())->load(
                [[
                    'rr_config_path' => 'does-not-exist.yaml',
                    'kv' => ['auto_register' => false],
                    'temporal' => [],
                ]],
                $container,
            );
        } finally {
            if ($originalEnv !== null) {
                $_ENV['RR_RPC'] = $originalEnv;
            }
            if ($originalServer !== null) {
                $_SERVER['RR_RPC'] = $originalServer;
            }
        }
    }

    private function loadWithTracing(bool $tracing): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');

        // kv.auto_register off avoids the unrelated KV RPC probe. With no RoadRunner running,
        // the Temporal frontend address is resolved from the bundled .rr.yaml fixture
        // (rr_config_path) — resolveTemporalAddress throws if it cannot be resolved.
        (new FluffyDiscordRoadRunnerExtension())->load(
            [[
                'rr_config_path' => 'temporal.rr.yaml',
                'kv' => ['auto_register' => false],
                'temporal' => ['tracing' => $tracing],
            ]],
            $container,
        );

        return $container;
    }
}
