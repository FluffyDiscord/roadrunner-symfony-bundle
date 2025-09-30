<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Tests\Attributes\SkipForSymfonyVersion;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionMethod($this, $this->name());

        foreach ($reflection->getAttributes(SkipForSymfonyVersion::class) as $attribute) {
            $instance = $attribute->newInstance();
            assert($instance instanceof SkipForSymfonyVersion);

            if ($instance->shouldSkip()) {
                $this->markTestSkipped("Skipping test for Symfony $instance->operator $instance->version");
            }
        }
    }
}