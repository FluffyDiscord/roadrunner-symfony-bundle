<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Client;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\WorkflowDefaults;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\RetryOptions;
use Temporal\Common\WorkflowIdConflictPolicy;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Workflow\WorkflowRunInterface;

/** Mutable fluent builder; WorkflowLauncher::of() hands out a fresh instance per call. */
final class PendingWorkflowStart
{
    private ?string $id = null;
    private ?string $queue = null;
    private ?IdReusePolicy $reusePolicy = null;
    private ?WorkflowIdConflictPolicy $conflictPolicy = null;
    private int|string|\DateInterval|null $executionTimeout = null;
    /** @var int<0, max>|null */
    private ?int $retryAttempts = null;
    private ?float $retryBackoff = null;

    /** @param class-string $workflowInterface */
    public function __construct(
        private readonly WorkflowClientInterface $client,
        private readonly string $workflowInterface,
        ?WorkflowDefaults $defaults = null,
    )
    {
        if ($defaults !== null) {
            $this->queue = $defaults->queue;
            $this->reusePolicy = $defaults->reusePolicy;
            $this->conflictPolicy = $defaults->conflictPolicy;
            $this->executionTimeout = $defaults->executionTimeout;
            $this->retryAttempts = $defaults->retryAttempts;
            $this->retryBackoff = $defaults->retryBackoff;
        }
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function queue(string $taskQueue): self
    {
        $this->queue = $taskQueue;

        return $this;
    }

    public function reusePolicy(IdReusePolicy $policy): self
    {
        $this->reusePolicy = $policy;

        return $this;
    }

    public function conflictPolicy(WorkflowIdConflictPolicy $policy): self
    {
        $this->conflictPolicy = $policy;

        return $this;
    }

    public function executionTimeout(int|string|\DateInterval $timeout): self
    {
        $this->executionTimeout = $timeout;

        return $this;
    }

    /** @param int<0, max> $attempts */
    public function retry(int $attempts, float $backoff = 2.0): self
    {
        $this->retryAttempts = $attempts;
        $this->retryBackoff = $backoff;

        return $this;
    }

    public function start(mixed ...$args): WorkflowRunInterface
    {
        $stub = $this->client->newWorkflowStub($this->workflowInterface, $this->options());

        return $this->client->start($stub, ...$args);
    }

    public function startOrSkip(mixed ...$args): ?WorkflowRunInterface
    {
        try {
            return $this->start(...$args);
        } catch (WorkflowExecutionAlreadyStartedException) {
            return null;
        }
    }

    private function options(): WorkflowOptions
    {
        $options = WorkflowOptions::new();

        if ($this->id !== null) {
            $options = $options->withWorkflowId($this->id);
        }
        if ($this->queue !== null) {
            $options = $options->withTaskQueue($this->queue);
        }
        if ($this->reusePolicy !== null) {
            $options = $options->withWorkflowIdReusePolicy($this->reusePolicy);
        }
        if ($this->conflictPolicy !== null) {
            $options = $options->withWorkflowIdConflictPolicy($this->conflictPolicy);
        }
        if ($this->executionTimeout !== null) {
            $options = $options->withWorkflowExecutionTimeout($this->executionTimeout);
        }
        if ($this->retryAttempts !== null) {
            $options = $options->withRetryOptions(
                RetryOptions::new()
                    ->withMaximumAttempts($this->retryAttempts)
                    ->withBackoffCoefficient($this->retryBackoff ?? 2.0),
            );
        }

        return $options;
    }
}
