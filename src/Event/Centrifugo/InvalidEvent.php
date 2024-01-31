<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use RoadRunner\Centrifugo\Payload\ResponseInterface;
use RoadRunner\Centrifugo\Request\Invalid;
use Symfony\Contracts\EventDispatcher\Event;

class InvalidEvent extends Event implements CentrifugoEventInterface
{
    public function __construct(
        private readonly Invalid $request,
    )
    {
    }

    public function getRequest(): Invalid
    {
        return $this->request;
    }

    public function getResponse(): ?ResponseInterface
    {
        return null;
    }

    public function setResponse(?ResponseInterface $response): CentrifugoEventInterface
    {
        throw new \RuntimeException('Setting response for invalid request is not supported');
    }
}