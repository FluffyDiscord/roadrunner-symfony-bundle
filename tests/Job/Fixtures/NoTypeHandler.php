<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

/**
 * Handler whose __invoke first parameter has no class type hint, so #[AsJobHandler] message inference
 * must fail at compile time.
 */
final class NoTypeHandler
{
    public function __invoke(mixed $message): void
    {
    }
}
