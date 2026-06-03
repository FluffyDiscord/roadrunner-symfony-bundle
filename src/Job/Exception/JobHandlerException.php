<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Exception;

/**
 * Thrown when an #[AsJobHandler] service cannot be resolved from the locator, or when invoking the
 * handler method throws. The original handler/locator failure is preserved as the previous exception.
 * It propagates out of the JobsRunEvent listener, so the worker nacks-with-requeue the task.
 */
final class JobHandlerException extends \RuntimeException
{
}
