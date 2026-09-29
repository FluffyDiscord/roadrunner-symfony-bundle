<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Command;

use FluffyDiscord\RoadRunnerBundle\Command\TemporalDebugCommand;
use FluffyDiscord\RoadRunnerBundle\Temporal\Debug\TemporalIntrospector;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubGoodWorkflow;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\BatchIsolatingHostConnection;
use Symfony\Component\HttpKernel\KernelInterface;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;

class TemporalDebugCommandTest extends BaseTestCase
{
    private function runCommand(array $input): CommandTester
    {
        $initializer = new TemporalWorkerInitializer(
            $this->createStub(KernelInterface::class),
            $this->createStub(BatchIsolatingHostConnection::class),
            new ExceptionInterceptor([\Error::class]),
            new SimplePipelineProvider([]),
            ['billing' => ['maxConcurrentActivityExecutionSize' => 4]],
        );
        $initializer->addWorkflow(StubGoodWorkflow::class, ['default']);
        $initializer->addActivity(GreetingActivity::class, ['billing']);

        $application = new Application();
        $application->addCommand(new TemporalDebugCommand(new TemporalIntrospector($initializer)));

        $tester = new CommandTester($application->find('debug:temporal'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testTextListsQueuesOptionsWorkflowsStubsAndActivities(): void
    {
        $output = $this->runCommand([])->getDisplay();

        self::assertStringContainsString('default', $output);
        self::assertStringContainsString('Worker options: SDK defaults', $output);
        self::assertStringContainsString('billing', $output);
        self::assertStringContainsString('"maxConcurrentActivityExecutionSize":4', $output);
        self::assertStringContainsString('StubGoodWorkflow', $output);
        self::assertStringContainsString('GreetingActivity -> default (inherited) | 5 minutes | retry=3', $output);
        self::assertStringContainsString('greeting.greet', $output);
    }

    public function testJsonIsMachineReadable(): void
    {
        $report = json_decode($this->runCommand(['--format' => 'json'])->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(StubGoodWorkflow::class, $report['default']['workflows'][0]['class']);
        self::assertSame('greet', $report['default']['workflows'][0]['stubs'][0]['property']);
        self::assertSame(['maxConcurrentActivityExecutionSize' => 4], $report['billing']['options']);
        self::assertSame(['greeting.greet'], $report['billing']['activities'][0]['ids']);
    }

    public function testMermaidDrawsWorkflowToActivityEdges(): void
    {
        $output = $this->runCommand(['--format' => 'mermaid'])->getDisplay();

        self::assertStringStartsWith('flowchart LR', $output);
        self::assertStringContainsString('subgraph billing', $output);
        self::assertStringContainsString('StubGoodWorkflow -->|"default (inherited) | 5 minutes"| GreetingActivity', $output);
    }
}
