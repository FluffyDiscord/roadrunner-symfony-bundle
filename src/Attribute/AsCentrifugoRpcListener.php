<?php

namespace FluffyDiscord\RoadRunnerBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsCentrifugoRpcListener
{
    /**
     * @param string      $rpcMethod The RPC method name to route to (exact match against RPCEvent::getRequest()->method).
     * @param int         $priority  Higher value = called first within the router's inner dispatch.
     * @param string|null $method    Service method to call. Auto-detected when attribute is placed on a method.
     */
    public function __construct(
        public readonly string  $rpcMethod,
        public readonly int     $priority = 0,
        public readonly ?string $method   = null,
    ) {
    }
}
