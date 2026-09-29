<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

class ProfilerKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $varDirectory)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new FluffyDiscordRoadRunnerBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->varDirectory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDirectory . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'   => 'test',
            'test'     => true,
            'profiler' => ['collect' => true],
            'messenger' => ['enabled' => true],
        ]);

        $container->extension('fluffy_discord_road_runner', [
            'rr_config_path' => 'temporal.rr.yaml',
            'kv'             => ['auto_register' => false],
        ]);
    }
}
