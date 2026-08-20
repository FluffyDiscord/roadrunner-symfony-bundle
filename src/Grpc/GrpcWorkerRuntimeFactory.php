<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcServiceConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class GrpcWorkerRuntimeFactory
{
    public function __construct(
        private readonly EventDispatcherInterface        $eventDispatcher,
        private readonly GrpcServiceRegistry             $serviceRegistry,
        private readonly GrpcInvoker                     $invoker,
        private readonly ?GrpcCallAuthenticatorInterface $authenticator,
        private readonly ?ServicesResetterInterface      $servicesResetter,
    )
    {
    }

    public function create(): GrpcWorkerRuntime
    {
        $routingTable = GrpcRoutingTable::fromRegistry($this->serviceRegistry);
        $this->assertAccessAttributesAreEnforced($routingTable);

        return new GrpcWorkerRuntime($this->eventDispatcher, $this->invoker, $routingTable, $this->authenticator, $this->servicesResetter);
    }

    private function assertAccessAttributesAreEnforced(GrpcRoutingTable $routingTable): void
    {
        $hasAccessAttributes = $routingTable->hasAccessAttributes();
        $hasGuard = $this->invoker->hasAuthorizationGuard();

        if ($hasAccessAttributes && !$hasGuard) {
            throw new GrpcServiceConfigurationException(sprintf('#[IsGranted] on a gRPC handler requires fluffy_discord_road_runner.grpc.security.enabled: true (%s)', $this->describeGuardedMethods($routingTable)));
        }
    }

    private function describeGuardedMethods(GrpcRoutingTable $routingTable): string
    {
        $guarded = [];

        foreach ($routingTable->getRoutes() as $route) {
            foreach ($route->methods as $methodName => $methodRoute) {
                if ($methodRoute->hasAccessAttributes()) {
                    $guarded[] = $route->service::class . '::' . $methodName;
                }
            }
        }

        return implode(', ', $guarded);
    }
}
