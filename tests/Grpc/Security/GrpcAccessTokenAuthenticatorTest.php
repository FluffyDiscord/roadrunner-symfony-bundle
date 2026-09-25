<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Security;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcSecurityConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAccessTokenAuthenticator;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\GRPC\Exception\UnauthenticatedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserChecker;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\FallbackUserLoader;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/** TC-26 / TC-27 / TC-28 */
#[AllowMockObjectsWithoutExpectations]
class GrpcAccessTokenAuthenticatorTest extends BaseTestCase
{
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenStorage = new TokenStorage();
    }

    private function makeAuthenticator(
        AccessTokenHandlerInterface $tokenHandler,
        ?UserProviderInterface      $userProvider = null,
        ?UserCheckerInterface       $userChecker = null,
        bool                        $required = true,
        string                      $tokenPrefix = 'Bearer ',
    ): GrpcAccessTokenAuthenticator {
        return new GrpcAccessTokenAuthenticator(
            tokenHandler: $tokenHandler,
            tokenStorage: $this->tokenStorage,
            userProvider: $userProvider,
            userChecker: $userChecker ?? new InMemoryUserChecker(),
            metadataKey: 'authorization',
            tokenPrefix: $tokenPrefix,
            required: $required,
            firewallName: 'grpc',
        );
    }

    private function makeHandler(UserBadge $badge): AccessTokenHandlerInterface
    {
        return new class($badge) implements AccessTokenHandlerInterface {
            public function __construct(private readonly UserBadge $badge)
            {
            }

            public function getUserBadgeFrom(string $accessToken): UserBadge
            {
                if ($accessToken !== 'ok') {
                    throw new BadCredentialsException('secret reason: token mismatch');
                }

                return $this->badge;
            }
        };
    }

    private function makeBadgeWithLoader(UserInterface $user): UserBadge
    {
        return new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user);
    }

    public function testValidTokenStoresAPostAuthenticationTokenWithChecks(): void
    {
        $alice = new InMemoryUser('alice', null, ['ROLE_USER']);
        $userChecker = $this->createMock(UserCheckerInterface::class);
        $userChecker->expects($this->once())->method('checkPreAuth')->with($alice);
        $userChecker->expects($this->once())->method('checkPostAuth');

        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader($alice)), userChecker: $userChecker);

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer ok']]));

        $token = $this->tokenStorage->getToken();
        self::assertInstanceOf(PostAuthenticationToken::class, $token);
        self::assertSame('alice', $token->getUserIdentifier());
        self::assertSame('grpc', $token->getFirewallName());
        self::assertContains('ROLE_USER', $token->getRoleNames());
    }

    public function testPrefixIsMatchedCaseInsensitively(): void
    {
        $alice = new InMemoryUser('alice', null, ['ROLE_USER']);
        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader($alice)));

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['bearer ok']]));

        self::assertNotNull($this->tokenStorage->getToken());
    }

    public function testMissingMetadataWithRequiredTrueIsUnauthenticated(): void
    {
        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader(new InMemoryUser('a', null))));

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('Missing credentials');

        $authenticator->authenticate(new GrpcMetadata([]));
    }

    public function testMissingMetadataWithRequiredFalseStaysAnonymous(): void
    {
        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader(new InMemoryUser('a', null))), required: false);

        $authenticator->authenticate(new GrpcMetadata([]));

        self::assertNull($this->tokenStorage->getToken());
    }

    public function testWrongPrefixIsInvalidCredentials(): void
    {
        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader(new InMemoryUser('a', null))));

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Basic ok']]));
    }

    public function testHandlerFailureMessageIsNeverForwarded(): void
    {
        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader(new InMemoryUser('a', null))));

        try {
            $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer wrong']]));
            self::fail('expected UnauthenticatedException');
        } catch (UnauthenticatedException $unauthenticated) {
            self::assertSame('Invalid credentials', $unauthenticated->getMessage());
            self::assertStringNotContainsString('secret reason', $unauthenticated->getMessage());
            self::assertInstanceOf(BadCredentialsException::class, $unauthenticated->getPrevious());
        }
    }

    public function testBadgeWithoutLoaderUsesTheProvider(): void
    {
        $alice = new InMemoryUser('alice', null, ['ROLE_USER']);
        $userProvider = $this->createMock(UserProviderInterface::class);
        $userProvider->expects($this->once())->method('loadUserByIdentifier')->with('alice')->willReturn($alice);

        $authenticator = $this->makeAuthenticator($this->makeHandler(new UserBadge('alice')), userProvider: $userProvider);

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer ok']]));

        self::assertSame('alice', $this->tokenStorage->getToken()?->getUserIdentifier());
    }

    public function testFallbackUserLoaderIsReplacedByTheProvider(): void
    {
        $alice = new InMemoryUser('alice', null, ['ROLE_USER']);
        $userProvider = $this->createMock(UserProviderInterface::class);
        $userProvider->expects($this->once())->method('loadUserByIdentifier')->with('alice')->willReturn($alice);

        $badge = new UserBadge('alice', new FallbackUserLoader(static fn (): ?UserInterface => null));
        $authenticator = $this->makeAuthenticator($this->makeHandler($badge), userProvider: $userProvider);

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer ok']]));

        self::assertNotNull($this->tokenStorage->getToken());
    }

    public function testBadgeWithoutLoaderAndNoProviderIsAConfigurationError(): void
    {
        $authenticator = $this->makeAuthenticator($this->makeHandler(new UserBadge('alice')));

        $this->expectException(GrpcSecurityConfigurationException::class);

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer ok']]));
    }

    public function testUnknownUserIsInvalidCredentials(): void
    {
        $badge = new UserBadge('ghost', static function (): ?UserInterface {
            throw new UserNotFoundException();
        });
        $authenticator = $this->makeAuthenticator($this->makeHandler($badge));

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer ok']]));
    }

    public function testDisabledUserIsInvalidCredentials(): void
    {
        $alice = new InMemoryUser('alice', null, ['ROLE_USER']);
        $userChecker = $this->createMock(UserCheckerInterface::class);
        $userChecker->method('checkPreAuth')->willThrowException(new DisabledException());

        $authenticator = $this->makeAuthenticator($this->makeHandler($this->makeBadgeWithLoader($alice)), userChecker: $userChecker);

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authenticator->authenticate(new GrpcMetadata(['authorization' => ['Bearer ok']]));
    }
}
