<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TypePHP\Exception\TypeError;

class TypePhpEnforcementTest extends BaseTestCase
{
    public function testSourceDocblockContractsAreEnforcedAtRuntime(): void
    {
        $disableFlag = getenv('TYPEPHP_DISABLE');
        $isDisabledByEnvironment = $disableFlag !== false && filter_var($disableFlag, FILTER_VALIDATE_BOOLEAN);
        $isDisabledByConstant = defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE;

        if ($isDisabledByEnvironment || $isDisabledByConstant) {
            self::markTestSkipped('TypePHP enforcement is disabled for this run');
        }

        $registry = new GrpcServiceRegistry(new ServiceLocator([]));

        $this->expectException(TypeError::class);

        $registry->addService('NotAnExistingClass', 'app.missing', 'NotAnExistingClass');
    }
}
