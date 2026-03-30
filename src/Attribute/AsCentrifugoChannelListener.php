<?php

namespace FluffyDiscord\RoadRunnerBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsCentrifugoChannelListener
{
    /**
     * @param string      $channel  Channel name to match. Supports '*' as a wildcard (e.g. 'chat:*').
     * @param string|null $event    Event class FQCN. Optional on methods — inferred from the first parameter type hint.
     *                              Must be one of: ConnectEvent, PublishEvent, SubscribeEvent, SubRefreshEvent.
     * @param int         $priority Higher value = called first within the router's inner dispatch.
     * @param string|null $method   Service method to call. Auto-detected when attribute is placed on a method.
     */
    public function __construct(
        public readonly string  $channel,
        public readonly ?string $event    = null,
        public readonly int     $priority = 0,
        public readonly ?string $method   = null,
    ) {
    }
}
