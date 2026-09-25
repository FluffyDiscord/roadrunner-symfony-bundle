<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\GrpcUserCheckerPass;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcIntrospector;
use FluffyDiscord\RoadRunnerBundle\Grpc\Debug\GrpcSecurityFacts;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAccessTokenAuthenticator;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\User\InMemoryUserChecker;

/** TC-24b — the gRPC user checker is resolved from SecurityBundle's own per-firewall alias */
class GrpcUserCheckerPassTest extends BaseTestCase
{
    private function makeContainer(string $firewallName, bool $withFirewallChecker): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('security.firewalls', ['main', 'api']);
        $container->register('security.user_checker', InMemoryUserChecker::class);
        $container->register('app.account_status_checker', InMemoryUserChecker::class);

        if ($withFirewallChecker) {
            $container->setAlias('security.user_checker.' . $firewallName, new Alias('app.account_status_checker', false));
        }

        $authenticator = new Definition(GrpcAccessTokenAuthenticator::class, [
            new Reference('app.token_handler'),
            new Reference('security.token_storage'),
            null,
            new Reference('security.user_checker'),
            'authorization',
            'Bearer ',
            true,
            $firewallName,
        ]);
        $container->setDefinition(GrpcAccessTokenAuthenticator::class, $authenticator);

        return $container;
    }

    public function testTheFirewallUserCheckerIsInjected(): void
    {
        $container = $this->makeContainer('main', withFirewallChecker: true);

        new GrpcUserCheckerPass()->process($container);

        $checkerReference = $container->getDefinition(GrpcAccessTokenAuthenticator::class)->getArgument(3);
        self::assertInstanceOf(Reference::class, $checkerReference);
        self::assertSame('security.user_checker.main', (string)$checkerReference);
    }

    public function testAFirewallNameMatchingNoFirewallIsRejected(): void
    {
        $container = $this->makeContainer('grpc', withFirewallChecker: false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/matches no security firewall/');

        new GrpcUserCheckerPass()->process($container);
    }

    public function testTheRejectionNamesOnlyFirewallsThatHaveAUserChecker(): void
    {
        $container = $this->makeContainer('grpc', withFirewallChecker: false);
        $container->setAlias('security.user_checker.main', new Alias('app.account_status_checker', false));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/usable firewalls: main\)$/');

        new GrpcUserCheckerPass()->process($container);
    }

    public function testTheRejectionSaysSoWhenNoFirewallQualifies(): void
    {
        $container = $this->makeContainer('grpc', withFirewallChecker: false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/no firewall in this application declares one/');

        new GrpcUserCheckerPass()->process($container);
    }

    public function testTheConstructorSlotsThePassWritesAreTheOnesItMeans(): void
    {
        $authenticatorParameters = new \ReflectionMethod(GrpcAccessTokenAuthenticator::class, '__construct')->getParameters();
        self::assertSame('userChecker', $authenticatorParameters[3]->getName());
        self::assertSame('firewallName', $authenticatorParameters[7]->getName());

        $factsParameters = new \ReflectionMethod(GrpcSecurityFacts::class, '__construct')->getParameters();
        self::assertSame('userCheckerId', $factsParameters[4]->getName());
    }

    public function testAnEmptyFirewallNameIsRejected(): void
    {
        $container = $this->makeContainer('', withFirewallChecker: false);

        $this->expectException(InvalidConfigurationException::class);

        new GrpcUserCheckerPass()->process($container);
    }

    public function testTheResolvedCheckerIsReportedToTheDebugCommand(): void
    {
        $container = $this->makeContainer('main', withFirewallChecker: true);
        $securityFacts = new Definition(GrpcSecurityFacts::class, [true, 'app.token_handler', 'authorization', true, null]);
        $container->setDefinition(GrpcIntrospector::class, new Definition(GrpcIntrospector::class, [null, null, $securityFacts]));

        new GrpcUserCheckerPass()->process($container);

        $facts = $container->getDefinition(GrpcIntrospector::class)->getArgument(2);
        self::assertInstanceOf(Definition::class, $facts);
        self::assertSame('security.user_checker.main', $facts->getArgument(4));
    }

    public function testThePassIsInertWithoutTheAuthenticator(): void
    {
        $container = new ContainerBuilder();

        new GrpcUserCheckerPass()->process($container);

        self::assertFalse($container->hasDefinition(GrpcAccessTokenAuthenticator::class));
    }
}
