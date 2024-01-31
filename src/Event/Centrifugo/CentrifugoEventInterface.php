<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use RoadRunner\Centrifugo\Payload\ResponseInterface;
use RoadRunner\Centrifugo\Request\RequestInterface;

interface CentrifugoEventInterface
{
    public function getResponse(): ?ResponseInterface;
    public function getRequest(): RequestInterface;
    public function setResponse(?ResponseInterface $response): self;
}