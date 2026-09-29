<?php

namespace FluffyDiscord\RoadRunnerBundle\DataCollector;

readonly class TemporalClientCall
{
    public function __construct(
        public string  $call,
        public ?string $workflowType,
        public string  $workflowId,
        public ?string $runId = null,
        public ?string $taskQueue = null,
        public ?string $name = null,
        public int     $sequence = 0,
        public float   $durationMilliseconds = 0.0,
        public ?string $error = null,
    )
    {
    }

    public function withOutcome(int $sequence, ?string $runId, float $durationMilliseconds, ?string $error): self
    {
        return new self(
            call: $this->call,
            workflowType: $this->workflowType,
            workflowId: $this->workflowId,
            runId: $runId ?? $this->runId,
            taskQueue: $this->taskQueue,
            name: $this->name,
            sequence: $sequence,
            durationMilliseconds: $durationMilliseconds,
            error: $error,
        );
    }
}
