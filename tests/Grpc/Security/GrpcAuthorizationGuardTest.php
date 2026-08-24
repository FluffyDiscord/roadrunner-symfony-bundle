<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Security;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMethodRoute;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAuthorizationGuard;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\UnauthenticatedException;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/** TC-29 (runtime part; the build-time validation is covered by GrpcRoutingTableTest) */
#[AllowMockObjectsWithoutExpectations]
class GrpcAuthorizationGuardTest extends BaseTestCase
{
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenStorage = new TokenStorage();
    }

    private function makeMethodRoute(IsGranted ...$attributes): GrpcMethodRoute
    {
        $method = Method::parse(new \ReflectionMethod(EchoInterface::class, 'WhoAmI'));

        return new GrpcMethodRoute($method, array_values($attributes));
    }

    private function authenticate(): void
    {
        $alice = new InMemoryUser('alice', null, ['ROLE_USER']);
        $this->tokenStorage->setToken(new PostAuthenticationToken($alice, 'grpc', $alice->getRoles()));
    }

    public function testNoAttributesIsANoOp(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects($this->never())->method('isGranted');

        new GrpcAuthorizationGuard($checker, $this->tokenStorage)->assertGranted($this->makeMethodRoute(), new WhoAmIRequest());
    }

    public function testAnonymousCallerOnAGuardedMethodIsUnauthenticated(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $guard = new GrpcAuthorizationGuard($checker, $this->tokenStorage);

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('Authentication required');

        $guard->assertGranted($this->makeMethodRoute(new IsGranted('ROLE_USER')), new WhoAmIRequest());
    }

    public function testDeniedAuthenticatedUserIsPermissionDenied(): void
    {
        $this->authenticate();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $guard = new GrpcAuthorizationGuard($checker, $this->tokenStorage);

        try {
            $guard->assertGranted($this->makeMethodRoute(new IsGranted('ROLE_ADMIN', message: 'admins only')), new WhoAmIRequest());
            self::fail('expected GRPCException');
        } catch (GRPCException $denied) {
            self::assertSame(StatusCode::PERMISSION_DENIED, $denied->getCode());
            self::assertSame('admins only', $denied->getMessage());
        }
    }

    public function testGrantedUserPasses(): void
    {
        $this->authenticate();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects($this->once())->method('isGranted')->with('ROLE_USER', null)->willReturn(true);

        new GrpcAuthorizationGuard($checker, $this->tokenStorage)->assertGranted($this->makeMethodRoute(new IsGranted('ROLE_USER')), new WhoAmIRequest());
    }

    public function testRequestSubjectPassesTheDecodedMessageInstance(): void
    {
        $this->authenticate();
        $request = new WhoAmIRequest();
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects($this->once())->method('isGranted')->with('VIEW', $this->identicalTo($request))->willReturn(true);

        new GrpcAuthorizationGuard($checker, $this->tokenStorage)->assertGranted($this->makeMethodRoute(new IsGranted('VIEW', 'request')), $request);
    }
}
