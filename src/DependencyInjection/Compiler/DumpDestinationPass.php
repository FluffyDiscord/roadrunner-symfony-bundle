<?php

namespace FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Resolves the app's configured dump server (debug.dump_destination), which DebugBundle exposes
 * only as the first argument of var_dumper.server_connection.
 */
class DumpDestinationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $dumpCaptureIsRegistered = $container->hasDefinition(DumpCapture::class);
        if (!$dumpCaptureIsRegistered) {
            return;
        }

        $dumpDestination = $this->getDumpDestination($container);
        if ($dumpDestination === null) {
            return;
        }

        $container->getDefinition(DumpCapture::class)->setArgument(3, $dumpDestination);
    }

    private function getDumpDestination(ContainerBuilder $container): ?string
    {
        $serverConnectionIsRegistered = $container->hasDefinition('var_dumper.server_connection');
        if (!$serverConnectionIsRegistered) {
            return null;
        }

        $serverConnection = $container->getDefinition('var_dumper.server_connection');
        $configuredHost = $serverConnection->getArgument(0);

        if (!is_string($configuredHost) || $configuredHost === '') {
            return null;
        }

        return $configuredHost;
    }
}
