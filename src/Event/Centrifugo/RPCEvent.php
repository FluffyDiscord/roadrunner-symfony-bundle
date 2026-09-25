<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use RoadRunner\Centrifugo\Payload\ResponseInterface;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Request\RPC;

class RPCEvent extends RefusableEvent
{
    private ?RPCResponse $response = null;

    public function __construct(
        private readonly RPC $request,
    )
    {
    }

    public function getRequest(): RPC
    {
        return $this->request;
    }

    public function getResponse(): ?RPCResponse
    {
        return $this->response;
    }

    public function setResponse(RPCResponse|ResponseInterface|null $response): self
    {
        $this->assertNotRefused();

        if ($response !== null && !$response instanceof RPCResponse) {
            throw new \InvalidArgumentException(sprintf('A listener for %s must call setResponse() with a %s, got %s.', self::class, RPCResponse::class, $response::class));
        }
        $this->response = $response;
        return $this;
    }
}