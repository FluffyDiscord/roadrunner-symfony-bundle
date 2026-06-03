<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Exception;

/**
 * Thrown when a job message cannot be serialized at dispatch, or cannot be decoded/rehydrated on
 * consume (missing strategy, unknown class, corrupt payload). On the consumer side it propagates out
 * of the JobsRunEvent listener, so the worker nacks-with-requeue the task.
 */
final class JobSerializationException extends \RuntimeException
{
}
