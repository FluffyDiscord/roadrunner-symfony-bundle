<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

/**
 * Instantiates the compiled URL matcher and generator without any HTTP request —
 * replaces the removed early_router_initialization dummy request, which crashed
 * host-based channel resolution. Wired to router.default (not the router alias):
 * decorators like Sylius's LocaleStrippingRouter hide the concrete Router behind
 * the alias, while FrameworkBundle keeps it under router.default. Never matches a
 * path — no route can be assumed to exist, and match() runs real lookup logic on
 * custom routers. See docs/specs/worker-warmup.md §3, ADR-10.
 */
readonly class RouterWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private ?RouterInterface $router = null,
    )
    {
    }

    public function warmup(): void
    {
        if (!$this->router instanceof Router) {
            return;
        }

        $this->router->getMatcher();
        $this->router->getGenerator();
    }
}
