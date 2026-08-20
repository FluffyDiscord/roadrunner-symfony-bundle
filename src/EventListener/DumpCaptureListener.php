<?php

namespace FluffyDiscord\RoadRunnerBundle\EventListener;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture;

class DumpCaptureListener
{
    public function __construct(
        private readonly DumpCapture $dumpCapture,
    )
    {
    }

    public function __invoke(): void
    {
        $this->dumpCapture->installHandler();
    }
}
