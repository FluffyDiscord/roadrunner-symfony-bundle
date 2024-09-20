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
        private bool                                        $secure = false,
    )
    {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $workerSessionStorage = new WorkerSessionStorage($this->options, $this->handler, $this->metaBag, $this->requestStack);

        if ($this->secure && $request?->isSecure()) {
            $workerSessionStorage->setOptions(['cookie_secure' => true]);
        }

        return $workerSessionStorage;
    }
}
