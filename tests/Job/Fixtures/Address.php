<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures;

/**
 * Nested object with a private property, used to prove both serializers restore private state and
 * nested object graphs.
 */
final class Address
{
    public function __construct(
        public string $city = '',
        private string $zip = '',
    ) {
    }

    public function getZip(): string
    {
        return $this->zip;
    }
}
