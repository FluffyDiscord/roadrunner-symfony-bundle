<?php

namespace FluffyDiscord\RoadRunnerBundle\Session;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;
use Symfony\Contracts\Service\ResetInterface;

class WorkerSessionStorageFactory implements SessionStorageFactoryInterface, ResetInterface
{
    private ?WorkerSessionStorage $workerSessionStorage = null;

    public function __construct(
        #[Autowire(param: "session.storage.options")]
        private readonly array                                       $options,

        #[Autowire(service: "session.handler")]
        private readonly AbstractProxy|\SessionHandlerInterface|null $handler,

        #[Autowire(service: "worker_session_factory_metadata_bag")]
        private readonly ?MetadataBag                                $metaBag,

        private readonly RequestStack                                $requestStack,
        private readonly bool                                        $secure = false,
    )
    {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        if ($this->workerSessionStorage === null) {
            $this->workerSessionStorage = new WorkerSessionStorage($this->options, $this->handler, $this->metaBag, $this->requestStack);
        }

        if ($this->secure && $request?->isSecure()) {
            $this->workerSessionStorage->setOptions(['cookie_secure' => true]);
        }

        return $this->workerSessionStorage;
    }

    public function reset(): void
    {
        $this->workerSessionStorage?->reset();
    }
}
