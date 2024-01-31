<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Payload\ResponseInterface;
use RoadRunner\Centrifugo\Request\Connect;
use Symfony\Contracts\EventDispatcher\Event;

class ConnectEvent extends Event implements CentrifugoEventInterface
{
    private ?ConnectResponse $response = null;

    public function __construct(
        private readonly Connect $request,
    )
    {
    }

    public function getRequest(): Connect
    {
        return $this->request;
    }

    public function getResponse(): ?ConnectResponse
    {
        return $this->response;
    }

    public function setResponse(ConnectResponse|ResponseInterface|null $response): self
    {
        $this->response = $response;
        return $this;
    }
}