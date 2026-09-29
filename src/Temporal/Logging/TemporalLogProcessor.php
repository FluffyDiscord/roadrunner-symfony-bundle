<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Temporal\Activity\ActivityContextInterface;
use Temporal\Activity\ActivityInfo;
use Temporal\Internal\Support\Facade;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowInfo;

readonly class TemporalLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $currentContext = Facade::getCurrentContext();

        $temporalContext = match (true) {
            $currentContext instanceof ActivityContextInterface => $this->getActivityContext($currentContext->getInfo()),
            $currentContext instanceof WorkflowContextInterface => $this->getWorkflowContext($currentContext->getInfo()),
            default                                             => null,
        };
        if ($temporalContext === null) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'temporal' => $temporalContext]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function getActivityContext(ActivityInfo $activityInfo): array
    {
        return [
            'workflowType' => $activityInfo->workflowType?->name,
            'workflowId'   => $activityInfo->workflowExecution?->getID(),
            'runId'        => $activityInfo->workflowExecution?->getRunID(),
            'activityType' => $activityInfo->type->name,
            'activityId'   => $activityInfo->id,
            'taskQueue'    => $activityInfo->taskQueue,
            'attempt'      => $activityInfo->attempt,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function getWorkflowContext(WorkflowInfo $workflowInfo): array
    {
        return [
            'workflowType' => $workflowInfo->type->name,
            'workflowId'   => $workflowInfo->execution->getID(),
            'runId'        => $workflowInfo->execution->getRunID(),
            'taskQueue'    => $workflowInfo->taskQueue,
            'attempt'      => $workflowInfo->attempt,
        ];
    }
}
