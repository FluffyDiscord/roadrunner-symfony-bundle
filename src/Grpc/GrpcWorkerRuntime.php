<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class GrpcWorkerRuntime
{
    public function __construct(
        public EventDispatcherInterface        $eventDispatcher,
        public GrpcInvoker                     $invoker,
        public GrpcRoutingTable                $routingTable,
        public ?GrpcCallAuthenticatorInterface $authenticator,
        public ?ServicesResetterInterface      $servicesResetter,
    )
    {
    }
}
