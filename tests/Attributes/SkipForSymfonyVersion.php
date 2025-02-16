<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Attributes;

use Composer\InstalledVersions;

#[\Attribute(\Attribute::TARGET_METHOD)]
class SkipForSymfonyVersion
{
    public function __construct(
        public readonly string $operator,
        public readonly string $version,
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
