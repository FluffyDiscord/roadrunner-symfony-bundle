<?php

namespace FluffyDiscord\RoadRunnerBundle;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\CentrifugoRouterPass;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\GrpcServicePass;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\DumpDestinationPass;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\RequestFactoryPass;
use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\TemporalWorkerPass;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use Spiral\RoadRunner\GRPC\ServiceInterface as GrpcServiceInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\VarDumper\VarDumper;
use Temporal\Workflow\WorkflowInterface;

final class FluffyDiscordRoadRunnerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RequestFactoryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);

        if (class_exists(RoadRunnerCentrifugoWorker::class)) {
            $container->addCompilerPass(new CentrifugoRouterPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }

        if (class_exists(VarDumper::class)) {
            $container->addCompilerPass(new DumpDestinationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }

        if (class_exists(WorkflowInterface::class)) {
            $container->addCompilerPass(new TemporalWorkerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }

        if (interface_exists(GrpcServiceInterface::class)) {
            $container->addCompilerPass(new GrpcServicePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }
    }

    public function boot(): void
    {
        // RoadRunner runs as PHP CLI; set APP_RUNTIME_MODE before any service reads kernel.runtime_mode.
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
