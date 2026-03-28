<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Attributes;

use Composer\InstalledVersions;

#[\Attribute(\Attribute::TARGET_METHOD)]
readonly class SkipForSymfonyVersion
{
    public function __construct(
        public string $operator,
        public string $version,
    )
    {
    }

    public function shouldSkip(): bool
    {
        if (!class_exists(InstalledVersions::class)) {
            return false;
        }

        $installedVersion = InstalledVersions::getVersion("symfony/dependency-injection");

        return version_compare($this->version, $installedVersion, $this->operator);
    }
}
