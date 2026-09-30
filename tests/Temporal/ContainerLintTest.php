<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\ProfilerKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class ContainerLintTest extends BaseTestCase
{
    private string $varDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->varDirectory = sys_get_temp_dir() . '/rr-bundle-container-lint-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->varDirectory);

        parent::tearDown();
    }

    public function testContainerWithTemporalEnabledPassesTheContainerLinter(): void
    {
        $kernel = new ProfilerKernel($this->varDirectory);
        $kernel->boot();

        $application = new Application($kernel);
        $commandTester = new CommandTester($application->find('lint:container'));
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode, $commandTester->getDisplay());

        $kernel->shutdown();
    }
}
