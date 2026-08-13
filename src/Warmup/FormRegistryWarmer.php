<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Resolves every registered form type (instantiation + resolved-type chain +
 * option resolvers), all otherwise built on first use per worker.
 * See docs/specs/worker-warmup.md §3.
 */
readonly class FormRegistryWarmer implements WorkerWarmerInterface
{
    /**
     * @param iterable<FormTypeInterface<mixed>> $formTypes services tagged form.type
     */
    public function __construct(
        private iterable               $formTypes = [],
        private ?FormRegistryInterface $formRegistry = null,
    )
    {
    }

    public function warmup(): void
    {
        if ($this->formRegistry === null) {
            return;
        }

        foreach ($this->formTypes as $formType) {
            try {
                $this->formRegistry->getType($formType::class);
            } catch (\Throwable) {
            }
        }
    }
}
