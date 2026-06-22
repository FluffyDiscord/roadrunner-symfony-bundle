<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * @template TInput of object
 */
#[Exclude]
abstract class MutableInputEvent
{
    /**
     * @param TInput $input
     */
    public function __construct(
        private object $input,
    )
    {
    }

    /**
     * @return TInput
     */
    public function getInput(): object
    {
        return $this->input;
    }

    /**
     * @param TInput $input
     */
    public function setInput(object $input): void
    {
        $this->input = $input;
    }
}
