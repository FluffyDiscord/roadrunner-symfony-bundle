<?php

namespace FluffyDiscord\RoadRunnerBundle\Job\Attribute;

/**
 * Registers a service (or one of its methods) as a handler for a job message class.
 *
 * Mirrors #[AsCentrifugoChannelListener]: placeable on a class (defaults to __invoke) or a method.
 * The handled message class may be given explicitly via $message or inferred from the handler
 * method's first parameter type hint.
 *
 * @see \FluffyDiscord\RoadRunnerBundle\Job\DependencyInjection\Compiler\JobHandlerPass
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsJobHandler
{
    /**
     * @param class-string|null $message  Message class FQCN this handler processes. Optional on a method —
     *                                     inferred from the first parameter type hint when omitted.
     * @param int               $priority Higher value = called first when several handlers match.
     * @param string|null       $method   Handler method. Auto-detected on a method target; defaults to __invoke.
     */
    public function __construct(
        public readonly ?string $message = null,
        public readonly int $priority = 0,
        public readonly ?string $method = null,
    ) {
    }
}
