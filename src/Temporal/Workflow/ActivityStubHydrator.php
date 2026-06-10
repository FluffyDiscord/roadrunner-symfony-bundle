<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Workflow;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;

/**
 * @internal Populates a workflow's #[ActivityStub] properties with Workflow::newActivityStub().
 *           Runs inside the deterministic workflow constructor seam; reads only attribute constants
 *           and reflection, so it is replay-safe.
 */
final class ActivityStubHydrator
{
    public static function hydrate(object $workflow): void
    {
        foreach (ActivityStubReader::pairs(new \ReflectionClass($workflow)) as [$property, $stub]) {
            $property->setValue($workflow, Workflow::newActivityStub($stub->activity, self::options($stub)));
        }
    }

    private static function options(ActivityStub $stub): ActivityOptions
    {
        $options = ActivityOptions::new();

        if ($stub->queue !== null) {
            $options = $options->withTaskQueue($stub->queue);
        }
        if ($stub->startToClose !== null) {
            $options = $options->withStartToCloseTimeout($stub->startToClose);
        }
        if ($stub->scheduleToClose !== null) {
            $options = $options->withScheduleToCloseTimeout($stub->scheduleToClose);
        }
        if ($stub->scheduleToStart !== null) {
            $options = $options->withScheduleToStartTimeout($stub->scheduleToStart);
        }
        if ($stub->heartbeat !== null) {
            $options = $options->withHeartbeatTimeout($stub->heartbeat);
        }

        $retry = self::retryOptions($stub);
        if ($retry !== null) {
            $options = $options->withRetryOptions($retry);
        }

        return $options;
    }

    private static function retryOptions(ActivityStub $stub): ?RetryOptions
    {
        if ($stub->retryAttempts === null
            && $stub->retryBackoff === null
            && $stub->retryInitialInterval === null
            && $stub->retryMaxInterval === null
            && $stub->nonRetryable === []
        ) {
            return null;
        }

        $retry = RetryOptions::new();

        if ($stub->retryAttempts !== null) {
            $retry = $retry->withMaximumAttempts($stub->retryAttempts);
        }
        if ($stub->retryBackoff !== null) {
            $retry = $retry->withBackoffCoefficient($stub->retryBackoff);
        }
        if ($stub->retryInitialInterval !== null) {
            $retry = $retry->withInitialInterval($stub->retryInitialInterval);
        }
        if ($stub->retryMaxInterval !== null) {
            $retry = $retry->withMaximumInterval($stub->retryMaxInterval);
        }
        if ($stub->nonRetryable !== []) {
            $retry = $retry->withNonRetryableExceptions($stub->nonRetryable);
        }

        return $retry;
    }
}
