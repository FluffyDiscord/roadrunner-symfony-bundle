<?php

namespace FluffyDiscord\RoadRunnerBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsCentrifugoRpcListener
{
    public function __construct(
        public readonly string  $rpcMethod,
        public readonly int     $priority = 0,
        public readonly ?string $method   = null,
    ) {
    }
}
