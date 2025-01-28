<?php

namespace FluffyDiscord\RoadRunnerBundle\Session;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

readonly class WorkerSessionStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(
        #[Autowire(param: "session.storage.options")]
        private array                                       $options,

        #[Autowire(service: "session.handler")]
        private AbstractProxy|\SessionHandlerInterface|null $handler,

        #[Autowire(service: "worker_session_factory_metadata_bag")]
        private ?MetadataBag                                $metaBag,

        private RequestStack                                $requestStack,
        private ?bool                                       $secure = null,
    )
    {
        if ($this->secure !== null) {
            trigger_deprecation("fluffydiscord/roadrunner-symfony-bundle", "3.2.0", 'Passing "$secure" in constructor is deprecated, use framework.session options instead');
        }
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $workerSessionStorage = new WorkerSessionStorage($this->options, $this->handler, $this->metaBag, $this->requestStack);

        if ($this->isSecure($request)) {
            $workerSessionStorage->setOptions(['cookie_secure' => true]);
        }

        return $workerSessionStorage;
    }

    private function isSecure(?Request $request): bool
    {
        $cookieSecure = $this->secure;
        if ($cookieSecure === null) {
            $cookieSecure = 'auto' === ($this->options['cookie_secure'] ?? null);
        }

        return $cookieSecure && $request?->isSecure();
    }
}
