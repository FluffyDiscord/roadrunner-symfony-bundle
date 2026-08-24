<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcInvoker;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcServiceRegistry;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcWorkerRuntimeFactory;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcDataCollector;
use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcProfilerSubscriber;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\GrpcWorker;
use FluffyDiscord\RoadRunnerBundle\Worker\WorkerRegistry;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** TC-23 */
class GrpcServiceWiringTest extends BaseTestCase
{
    private function loadServices(bool $debug = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        if ($debug) {
            $loader->load('debug.php');
        }

        return $container;
    }

    public function testGrpcWorkerIsRegisteredUnderGrpcMode(): void
    {
        $container = $this->loadServices();

        self::assertTrue($container->hasDefinition(GrpcWorker::class));
        self::assertTrue($container->getDefinition(GrpcWorker::class)->isPublic());
        self::assertTrue($container->getDefinition(GrpcWorkerRuntimeFactory::class)->isPublic());
        self::assertTrue($container->hasDefinition(GrpcServiceRegistry::class));
        self::assertTrue($container->hasDefinition(GrpcInvoker::class));

        $registeredModes = [];
        foreach ($container->getDefinition(WorkerRegistry::class)->getMethodCalls() as [$method, $arguments]) {
            if ($method === 'registerWorker') {
                $registeredModes[] = $arguments[0];
            }
        }

        self::assertContains(Mode::MODE_GRPC, $registeredModes);
    }

    public function testDebugConfigDefinesTheCollectorAndSubscriber(): void
    {
        $container = $this->loadServices(debug: true);

        self::assertTrue($container->hasDefinition(GrpcDataCollector::class));
        self::assertTrue($container->hasDefinition(GrpcProfilerSubscriber::class));

        $collectorTags = $container->getDefinition(GrpcDataCollector::class)->getTag('data_collector');
        self::assertSame('grpc', $collectorTags[0]['id']);
        self::assertSame(255, $collectorTags[0]['priority']);

        $subscriberTags = $container->getDefinition(GrpcProfilerSubscriber::class)->getTags();
        self::assertArrayHasKey('kernel.event_subscriber', $subscriberTags);
        self::assertArrayHasKey('kernel.reset', $subscriberTags);
    }
}
