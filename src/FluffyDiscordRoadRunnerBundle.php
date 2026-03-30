<?php

namespace FluffyDiscord\RoadRunnerBundle;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\CentrifugoRouterPass;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class FluffyDiscordRoadRunnerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(RoadRunnerCentrifugoWorker::class)) {
            $container->addCompilerPass(new CentrifugoRouterPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }
    }
}
