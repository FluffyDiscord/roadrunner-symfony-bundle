<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\WorkflowContextClearingHostConnection;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Temporal\Internal\Support\Facade;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;

class WorkflowContextClearingHostConnectionTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Workflow::setCurrentContext(null);

        parent::tearDown();
    }

    private function enterWorkflowContext(): void
    {
        Workflow::setCurrentContext($this->createStub(WorkflowContextInterface::class));
    }

    public function testSendingTheTickResponseLeavesTheWorkflowContext(): void
    {
        $this->enterWorkflowContext();

        $hostConnection = $this->createMock(HostConnectionInterface::class);
        $hostConnection->expects(self::once())->method('send')->with('frame');

        (new WorkflowContextClearingHostConnection($hostConnection))->send('frame');

        self::assertNull(Facade::getCurrentContext());
    }

    public function testReportingATickErrorLeavesTheWorkflowContext(): void
    {
        $this->enterWorkflowContext();
        $failure = new \RuntimeException('decode failed');

        $hostConnection = $this->createMock(HostConnectionInterface::class);
        $hostConnection->expects(self::once())->method('error')->with($failure);

        (new WorkflowContextClearingHostConnection($hostConnection))->error($failure);

        self::assertNull(Facade::getCurrentContext());
    }
}
