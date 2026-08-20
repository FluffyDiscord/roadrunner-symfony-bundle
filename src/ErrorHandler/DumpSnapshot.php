<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

readonly class DumpSnapshot
{
    public function __construct(
        public string  $location,
        public ?string $fileLink = null,
        public ?string $renderedDumps = null,
        public ?string $dumpDestination = null,
    )
    {
    }

    public function getLogSuffix(): string
    {
        return '; last dump ran at ' . $this->location;
    }
}
