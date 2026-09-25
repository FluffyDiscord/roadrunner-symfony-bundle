<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use Symfony\Contracts\EventDispatcher\Event;

abstract class RefusableEvent extends Event implements CentrifugoEventInterface
{
    private ?Refusal $refusal = null;

    public function reject(int $code, string $message, bool $temporary = false): void
    {
        $this->refuse(new Refusal(RefusalType::Error, $code, $message, $temporary));
    }

    public function disconnect(int $code, string $reason): void
    {
        $this->refuse(new Refusal(RefusalType::Disconnect, $code, $reason));
    }

    public function getRefusal(): ?Refusal
    {
        return $this->refusal;
    }

    protected function assertNotRefused(): void
    {
        if ($this->refusal !== null) {
            throw new \LogicException(sprintf('The %s was already refused; a refused request cannot also get a response or a second refusal.', static::class));
        }
    }

    private function refuse(Refusal $refusal): void
    {
        $this->assertNotRefused();

        $response = $this->getResponse();

        if ($response !== null) {
            throw new \LogicException(sprintf('The %s already has a response; it cannot also be refused.', static::class));
        }

        $this->refusal = $refusal;
        $this->stopPropagation();
    }
}
