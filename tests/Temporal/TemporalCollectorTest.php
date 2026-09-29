<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingWorkflow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Interceptor\WorkflowClient\SignalInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Workflow\WorkflowExecution;

/**
 * TC-15 — the profiler data collector.
 */
class TemporalCollectorTest extends BaseTestCase
{
    private function collector(?callable $configure = null): TemporalCollector
    {
        $initializer = new TemporalWorkerInitializer(
            $this->createStub(KernelInterface::class),
            $this->createStub(ServicesResetterInterface::class),
            new ExceptionInterceptor([\Error::class]),
            new SimplePipelineProvider([]),
            ['default' => ['maxConcurrentActivityExecutionSize' => 4]],
        );

        if ($configure !== null) {
            $configure($initializer);
        }

        return new TemporalCollector(new TemporalIntrospector($initializer));
    }

    private function startInput(): StartInput
    {
        return new StartInput(
            'greet-world',
            'GreetingWorkflow',
            Header::empty(),
            EncodedValues::empty(),
            WorkflowOptions::new()->withTaskQueue('billing'),
        );
    }

    public function testCollectsWorkersWorkflowsAndActivities(): void
    {
        $collector = $this->collector(static function (TemporalWorkerInitializer $initializer): void {
            $initializer->addWorkflow(GreetingWorkflow::class, ['default']);
            $initializer->addActivity(GreetingActivity::class, ['default']);
        });
        $collector->collect(new Request(), new Response());

        $workers = $collector->getWorkers();
        self::assertCount(1, $workers);
        self::assertSame('default', $workers[0]['taskQueue']);
        self::assertSame(['maxConcurrentActivityExecutionSize' => '4'], $workers[0]['options']);

        $workflows = $collector->getWorkflows();
        self::assertArrayHasKey(GreetingWorkflow::class, $workflows);
        self::assertSame(['default'], $workflows[GreetingWorkflow::class]['taskQueues']);
        self::assertSame(['GreetingWorkflow'], $workflows[GreetingWorkflow::class]['ids']);

        $activities = $collector->getActivities();
        self::assertArrayHasKey(GreetingActivity::class, $activities);
        self::assertSame(['default'], $activities[GreetingActivity::class]['taskQueues']);
        self::assertSame(['greeting.greet'], $activities[GreetingActivity::class]['ids']);
    }

    public function testRecordsSuccessfulStartWithRunId(): void
    {
        $collector = $this->collector();

        $execution = $collector->start($this->startInput(), static fn (StartInput $input): WorkflowExecution => new WorkflowExecution($input->workflowId, 'run-1'));
        $collector->collect(new Request(), new Response());

        self::assertSame('run-1', $execution->getRunID());

        $calls = $collector->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('start', $calls[0]->call);
        self::assertSame('GreetingWorkflow', $calls[0]->workflowType);
        self::assertSame('greet-world', $calls[0]->workflowId);
        self::assertSame('run-1', $calls[0]->runId);
        self::assertSame('billing', $calls[0]->taskQueue);
        self::assertNull($calls[0]->error);
        self::assertSame(0, $collector->getFailedCallCount());
    }

    public function testRecordsFailedCallAndRethrows(): void
    {
        $collector = $this->collector();
        $input = new SignalInput(new WorkflowExecution('greet-world', 'run-1'), 'GreetingWorkflow', 'approve', EncodedValues::empty(), Header::empty());

        try {
            $collector->signal($input, static fn () => throw new \RuntimeException('workflow not found'));
            self::fail('The signal failure must be rethrown.');
        } catch (\RuntimeException) {
        }

        $collector->collect(new Request(), new Response());

        $calls = $collector->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('signal', $calls[0]->call);
        self::assertSame('approve', $calls[0]->name);
        self::assertSame('RuntimeException: workflow not found', $calls[0]->error);
        self::assertSame(1, $collector->getFailedCallCount());
    }

    public function testResetForgetsRecordedCalls(): void
    {
        $collector = $this->collector();
        $collector->start($this->startInput(), static fn (StartInput $input): WorkflowExecution => new WorkflowExecution($input->workflowId, 'run-1'));

        $collector->reset();
        $collector->collect(new Request(), new Response());

        self::assertSame([], $collector->getCalls());
    }

    public function testGettersAreSafeBeforeCollect(): void
    {
        $collector = $this->collector();

        self::assertSame([], $collector->getCalls());
        self::assertSame([], $collector->getWorkers());
        self::assertSame([], $collector->getWorkflows());
        self::assertSame([], $collector->getActivities());
    }
}
