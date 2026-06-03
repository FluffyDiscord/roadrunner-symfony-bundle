<?php

namespace FluffyDiscord\RoadRunnerBundle;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\CentrifugoRouterPass;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Job\DependencyInjection\Compiler\JobHandlerPass;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use Spiral\RoadRunner\Jobs\Consumer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Temporal\Workflow\WorkflowInterface;

final class FluffyDiscordRoadRunnerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(RoadRunnerCentrifugoWorker::class)) {
            $container->addCompilerPass(new CentrifugoRouterPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }

        if (class_exists(Consumer::class)) {
            $container->addCompilerPass(new JobHandlerPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }

        // The extension doubles as a compiler pass that scans for Temporal workflows/activities.
        $extension = parent::getContainerExtension();
        if (class_exists(WorkflowInterface::class) && $extension instanceof CompilerPassInterface) {
            $container->addCompilerPass($extension, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }
    }

    public function boot(): void
    {
        // Symfony resolves APP_RUNTIME_MODE to choose HtmlErrorRenderer vs
        // CliErrorRenderer (and to configure profiling, dump collection, etc.).
        // RoadRunner runs as PHP CLI so the default resolves to "cli".  We must
        // set the correct value before any service reads kernel.runtime_mode.*,
        // and Bundle::boot() runs before any lazy service is instantiated.
        $mode = $_SERVER['RR_MODE'] ?? null;
        $paramName = match ($mode) {
            'http' => 'fluffy_discord.runtime_mode.http',
            'centrifuge' => 'fluffy_discord.runtime_mode.centrifuge',
            default => null,
        };

        if ($paramName !== null && $this->container !== null && $this->container->hasParameter($paramName)) {
            /** @var string $runtimeMode */
            $runtimeMode = $this->container->getParameter($paramName);
            $_SERVER['APP_RUNTIME_MODE'] = $runtimeMode;
        }
    }
}
