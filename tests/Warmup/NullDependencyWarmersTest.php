<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use FluffyDiscord\RoadRunnerBundle\Warmup\DoctrineWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\EventListenersWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\FormRegistryWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\RouterWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\TwigRuntimesWarmer;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

/** See docs/specs/worker-warmup.md §9 (U15, U15b, U15c): absent dependencies mean no-op, never a throw. */
#[AllowMockObjectsWithoutExpectations]
class NullDependencyWarmersTest extends BaseTestCase
{
    public function testAllWarmersNoOpWithoutDependencies(): void
    {
        new RouterWarmer(null)->warmup();
        new DoctrineWarmer(null)->warmup();
        new EventListenersWarmer(null)->warmup();
        new FormRegistryWarmer([], null)->warmup();
        new TwigRuntimesWarmer([])->warmup();

        $this->expectNotToPerformAssertions();
    }

    public function testTwigRuntimesWarmerInstantiatesLazyIterables(): void
    {
        $instantiated = false;
        $lazyRuntimes = (function () use (&$instantiated) {
            $instantiated = true;
            yield new \stdClass();
        })();

        new TwigRuntimesWarmer($lazyRuntimes)->warmup();

        self::assertTrue($instantiated);
    }

    public function testRouterWarmerWarmsConcreteRouter(): void
    {
        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('getMatcher')
            ->willReturn($this->createMock(\Symfony\Component\Routing\Matcher\UrlMatcherInterface::class));
        $router->expects($this->once())->method('getGenerator');

        new RouterWarmer($router)->warmup();
    }

    public function testRouterWarmerIgnoresNonRouterImplementations(): void
    {
        // No route path may be assumed to exist, so a router that does not expose
        // the compiled matcher/generator gets no call at all — match() especially.
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->never())->method($this->anything());

        new RouterWarmer($router)->warmup();
    }
}
