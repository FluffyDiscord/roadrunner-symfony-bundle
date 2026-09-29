<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DataCollector\TemporalCollector;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\ProfilerKernel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Client\WorkflowOptions;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\Header;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowClientCallsInterceptor;
use Temporal\Workflow\WorkflowExecution;

class ProfilerContainerTest extends BaseTestCase
{
    private string $varDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->varDirectory = sys_get_temp_dir() . '/rr-bundle-profiler-kernel-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->varDirectory);

        parent::tearDown();
    }

    public function testClientCallsThroughTheRealPipelineReachTheCollector(): void
    {
        $kernel = new ProfilerKernel($this->varDirectory);
        $kernel->boot();
        $testContainer = $kernel->getContainer()->get('test.service_container');

        $pipelineProvider = $testContainer->get(PipelineProvider::class);
        $startCall = $pipelineProvider
            ->getPipeline(WorkflowClientCallsInterceptor::class)
            ->with(static fn (StartInput $input): WorkflowExecution => new WorkflowExecution($input->workflowId, 'run-1'), 'start');
        $startCall(new StartInput('greet-world', 'GreetingWorkflow', Header::empty(), EncodedValues::empty(), WorkflowOptions::new()));

        $collector = $testContainer->get(TemporalCollector::class);
        $collector->collect(new Request(), new Response());

        self::assertSame('greet-world', $collector->getCalls()[0]->workflowId);

        $kernel->shutdown();
    }
}
