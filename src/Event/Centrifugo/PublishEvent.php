<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use RoadRunner\Centrifugo\Payload\PublishResponse;
use RoadRunner\Centrifugo\Payload\ResponseInterface;
use RoadRunner\Centrifugo\Request\Publish;
use Symfony\Contracts\EventDispatcher\Event;

class PublishEvent extends Event implements CentrifugoEventInterface
{
    private ?PublishResponse $response = null;

    public function __construct(
        private readonly Publish $request,
    )
    {
    }

    public function getRequest(): Publish
    {
        return $this->request;
    }

    public function getResponse(): ?PublishResponse
    {
        return $this->response;
    }

    public function setResponse(PublishResponse|ResponseInterface|null $response): self
    {
        $this->response = $response;
        return $this;
    }
}