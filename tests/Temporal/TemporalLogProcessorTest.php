<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Logging\TemporalLogProcessor;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Monolog\Level;
use Monolog\LogRecord;
use Temporal\Activity;
use Temporal\Activity\ActivityContextInterface;
use Temporal\Activity\ActivityInfo;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowInfo;
use Temporal\Workflow\WorkflowType;

class TemporalLogProcessorTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Activity::setCurrentContext(null);

        parent::tearDown();
    }

    private function createRecord(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'Importing document', extra: ['request_id' => 'abc']);
    }

    private function createWorkflowType(string $name): WorkflowType
    {
        $workflowType = new WorkflowType();
        $workflowType->name = $name;

        return $workflowType;
    }

    public function testActivityLogCarriesItsWorkflowAndAttempt(): void
    {
        $activityInfo = new ActivityInfo();
        $activityInfo->id = '5';
        $activityInfo->type->name = 'ingest.import';
        $activityInfo->attempt = 2;
        $activityInfo->taskQueue = 'ingest';
        $activityInfo->workflowType = $this->createWorkflowType('IngestWorkflow');
        $activityInfo->workflowExecution = new WorkflowExecution('ingest-42', 'run-1');

        $context = $this->createStub(ActivityContextInterface::class);
        $context->method('getInfo')->willReturn($activityInfo);
        Activity::setCurrentContext($context);

        $record = (new TemporalLogProcessor())($this->createRecord());

        self::assertSame([
            'request_id' => 'abc',
            'temporal'   => [
                'workflowType' => 'IngestWorkflow',
                'workflowId'   => 'ingest-42',
                'runId'        => 'run-1',
                'activityType' => 'ingest.import',
                'activityId'   => '5',
                'taskQueue'    => 'ingest',
                'attempt'      => 2,
            ],
        ], $record->extra);
    }

    public function testWorkflowLogCarriesItsExecution(): void
    {
        $workflowInfo = new WorkflowInfo();
        $workflowInfo->type = $this->createWorkflowType('IngestWorkflow');
        $workflowInfo->execution = new WorkflowExecution('ingest-42', 'run-1');
        $workflowInfo->taskQueue = 'ingest';

        $context = $this->createStub(WorkflowContextInterface::class);
        $context->method('getInfo')->willReturn($workflowInfo);
        Workflow::setCurrentContext($context);

        $record = (new TemporalLogProcessor())($this->createRecord());

        self::assertSame([
            'workflowType' => 'IngestWorkflow',
            'workflowId'   => 'ingest-42',
            'runId'        => 'run-1',
            'taskQueue'    => 'ingest',
            'attempt'      => 1,
        ], $record->extra['temporal']);
    }

    public function testLogOutsideTemporalIsUntouched(): void
    {
        $record = $this->createRecord();

        self::assertSame($record, (new TemporalLogProcessor())($record));
    }
}
