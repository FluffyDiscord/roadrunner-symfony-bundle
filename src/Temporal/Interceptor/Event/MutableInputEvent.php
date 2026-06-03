<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Base class for every interceptor event the bundle dispatches. Each event carries the
 * SDK interceptor input object and lets a listener swap it out via {@see setInput()}
 * before it is handed to the next interceptor in the pipeline.
 *
 * The concrete event subclasses bind the generic to their specific input type via a
 * `@extends MutableInputEvent<TInput>` docblock, so listeners keep full type-safety on
 * `getInput()`/`setInput()` while the body stays here.
 *
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
