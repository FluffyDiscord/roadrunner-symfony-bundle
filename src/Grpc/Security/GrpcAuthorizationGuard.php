<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Security;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMethodRoute;
use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\UnauthenticatedException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GrpcAuthorizationGuard
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TokenStorageInterface         $tokenStorage,
    )
    {
    }

    public function assertGranted(GrpcMethodRoute $methodRoute, Message $request): void
    {
        $hasAccessAttributes = $methodRoute->hasAccessAttributes();

        if (!$hasAccessAttributes) {
            return;
        }

        $this->assertAuthenticated();

        foreach ($methodRoute->accessAttributes as $accessAttribute) {
            $this->assertAttributeGranted($accessAttribute, $request);
        }
    }

    private function assertAuthenticated(): void
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if ($user === null) {
            throw UnauthenticatedException::create('Authentication required', StatusCode::UNAUTHENTICATED);
        }
    }

    private function assertAttributeGranted(IsGranted $accessAttribute, Message $request): void
    {
        $subject = $accessAttribute->subject === 'request' ? $request : null;
        $granted = $this->authorizationChecker->isGranted($accessAttribute->attribute, $subject);

        if (!$granted) {
            throw GRPCException::create($accessAttribute->message ?? 'Access denied', StatusCode::PERMISSION_DENIED);
        }
    }
}
