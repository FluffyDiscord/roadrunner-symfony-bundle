<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

/**
 * Iterating the tagged twig.runtime services instantiates them — that is the
 * entire warm-up. See docs/specs/worker-warmup.md §3.
 */
readonly class TwigRuntimesWarmer implements WorkerWarmerInterface
{
    /**
     * @param iterable<object> $twigRuntimes services tagged twig.runtime
     */
    public function __construct(
        private iterable $twigRuntimes = [],
    )
    {
    }

    public function warmup(): void
    {
        foreach ($this->twigRuntimes as $twigRuntime) {
            // Advancing the lazy tagged iterator instantiates the runtime — that IS the warm-up.
        }
    }
}
