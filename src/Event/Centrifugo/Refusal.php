<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

use RoadRunner\Centrifugo\Request\RequestInterface;

readonly class Refusal
{
    public function __construct(
        public RefusalType $type,
        public int         $code,
        public string      $message,
        public bool        $temporary = false,
    )
    {
        match ($type) {
            RefusalType::Error      => $this->assertValidError(),
            RefusalType::Disconnect => $this->assertValidDisconnect(),
        };
    }

    public function sendTo(RequestInterface $request): void
    {
        match ($this->type) {
            RefusalType::Error      => $request->error($this->code, $this->message, $this->temporary),
            RefusalType::Disconnect => $request->disconnect($this->code, $this->message),
        };
    }

    private function assertValidError(): void
    {
        $isCodeInRange = $this->code >= 400 && $this->code <= 1999;

        if (!$isCodeInRange) {
            throw new \InvalidArgumentException(sprintf('Centrifugo error code must be within 400..1999, got %d.', $this->code));
        }
    }

    private function assertValidDisconnect(): void
    {
        $isCodeInRange = $this->code >= 4000 && $this->code <= 4999;

        if (!$isCodeInRange) {
            throw new \InvalidArgumentException(sprintf('Centrifugo disconnect code must be within 4000..4999, got %d.', $this->code));
        }

        $reasonLength = \strlen($this->message);

        if ($reasonLength > 32) {
            throw new \InvalidArgumentException(sprintf('Centrifugo disconnect reason must be at most 32 bytes, got %d.', $reasonLength));
        }

        if ($this->temporary) {
            throw new \InvalidArgumentException('A Centrifugo disconnect cannot be temporary; pick a 4000..4499 code to let the client reconnect.');
        }
    }
}
