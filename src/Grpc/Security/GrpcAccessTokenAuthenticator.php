<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Security;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcSecurityConfigurationException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use Spiral\RoadRunner\GRPC\Exception\UnauthenticatedException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\FallbackUserLoader;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

class GrpcAccessTokenAuthenticator implements GrpcCallAuthenticatorInterface
{
    public function __construct(
        private readonly AccessTokenHandlerInterface $tokenHandler,
        private readonly TokenStorageInterface       $tokenStorage,
        private readonly ?UserProviderInterface      $userProvider,
        private readonly ?UserCheckerInterface       $userChecker,
        private readonly string                      $metadataKey,
        private readonly string                      $tokenPrefix,
        private readonly bool                        $required,
        private readonly string                      $firewallName,
    )
    {
    }

    public function authenticate(GrpcMetadata $metadata): void
    {
        $rawValue = $metadata->getFirst($this->metadataKey);

        if ($rawValue === null) {
            $this->assertCredentialsOptional();

            return;
        }

        $accessToken = $this->stripPrefix($rawValue);
        $badge = $this->loadBadge($accessToken);
        $this->attachUserLoader($badge);
        $user = $this->loadUser($badge);
        $token = $this->buildToken($user);

        $this->tokenStorage->setToken($token);
    }

    private function assertCredentialsOptional(): void
    {
        if ($this->required) {
            throw UnauthenticatedException::create('Missing credentials', StatusCode::UNAUTHENTICATED);
        }
    }

    private function stripPrefix(string $rawValue): string
    {
        if ($this->tokenPrefix === '') {
            return $rawValue;
        }

        $hasPrefix = stripos($rawValue, $this->tokenPrefix) === 0;

        if (!$hasPrefix) {
            throw UnauthenticatedException::create('Invalid credentials', StatusCode::UNAUTHENTICATED);
        }

        return substr($rawValue, strlen($this->tokenPrefix));
    }

    private function loadBadge(string $accessToken): UserBadge
    {
        try {
            return $this->tokenHandler->getUserBadgeFrom($accessToken);
        } catch (AuthenticationException $authenticationException) {
            throw UnauthenticatedException::create('Invalid credentials', StatusCode::UNAUTHENTICATED, $authenticationException);
        }
    }

    private function attachUserLoader(UserBadge $badge): void
    {
        $loader = $badge->getUserLoader();
        $loaderIsReplaceable = $loader === null || $loader instanceof FallbackUserLoader;

        if (!$loaderIsReplaceable) {
            return;
        }

        if ($this->userProvider !== null) {
            $badge->setUserLoader($this->userProvider->loadUserByIdentifier(...));

            return;
        }

        if ($loader === null) {
            throw GrpcSecurityConfigurationException::create('grpc.security needs a user provider: the token handler returned a UserBadge without a loader; set fluffy_discord_road_runner.grpc.security.user_provider', StatusCode::INTERNAL);
        }
    }

    private function loadUser(UserBadge $badge): UserInterface
    {
        try {
            return $badge->getUser();
        } catch (AuthenticationException $authenticationException) {
            throw UnauthenticatedException::create('Invalid credentials', StatusCode::UNAUTHENTICATED, $authenticationException);
        }
    }

    private function buildToken(UserInterface $user): PostAuthenticationToken
    {
        try {
            $this->userChecker?->checkPreAuth($user);
            $token = new PostAuthenticationToken($user, $this->firewallName, $user->getRoles());
            $this->userChecker?->checkPostAuth($user, $token);
        } catch (AuthenticationException $accountStatusException) {
            throw UnauthenticatedException::create('Invalid credentials', StatusCode::UNAUTHENTICATED, $accountStatusException);
        }

        return $token;
    }
}
