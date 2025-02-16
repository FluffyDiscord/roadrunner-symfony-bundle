<?php

namespace FluffyDiscord\RoadRunnerBundle\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\Centrifugo\AfterRespondEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\NoCentrifugoResponseProvidedException;
use FluffyDiscord\RoadRunnerBundle\Exception\UnsupportedCentrifugoRequestTypeException;
use GuzzleHttp\Promise\PromiseInterface; // Sentry v4 compatibility
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Payload\PublishResponse;
use RoadRunner\Centrifugo\Payload\RefreshResponse;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Payload\SubRefreshResponse;
use RoadRunner\Centrifugo\Payload\SubscribeResponse;
use RoadRunner\Centrifugo\Request;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class CentrifugoWorker implements WorkerInterface
{
    public function __construct(
        private readonly bool                       $lazyBoot,
        private readonly KernelInterface            $kernel,
        private readonly RoadRunnerCentrifugoWorker $worker,
        private readonly EventDispatcherInterface   $eventDispatcher,
        private readonly ?SentryHubInterface        $sentryHubInterface = null,
    )
    {
    }

    public function start(): void
    {
        if (!$this->lazyBoot) {
            $this->kernel->boot();
        }

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        while ($request = $this->worker->waitRequest()) {
            $this->sentryHubInterface?->pushScope();

            try {
                // allow kernel to reset services
                $this->kernel->boot();

                $event = match (true) {
                    $request instanceof Request\Connect => new ConnectEvent($request),
                    $request instanceof Request\Publish => new PublishEvent($request),
                    $request instanceof Request\Refresh => new RefreshEvent($request),
                    $request instanceof Request\SubRefresh => new SubRefreshEvent($request),
                    $request instanceof Request\Subscribe => new SubscribeEvent($request),
                    $request instanceof Request\RPC => new RPCEvent($request),
                    $request instanceof Request\Invalid => new InvalidEvent($request),
                    default => throw new UnsupportedCentrifugoRequestTypeException(sprintf('Unsupported $request type: %s', $request::class)),
                };

                $processedEvent = $this->eventDispatcher->dispatch($event);
                assert($processedEvent instanceof CentrifugoEventInterface);

                if(!$event instanceof InvalidEvent) {
                    $response = $processedEvent->getResponse() ?? match (true) {
                        $event instanceof ConnectEvent => new ConnectResponse(),
                        $event instanceof PublishEvent => new PublishResponse(),
                        $event instanceof RefreshEvent => new RefreshResponse(),
                        $event instanceof SubRefreshEvent => new SubRefreshResponse(),
                        $event instanceof SubscribeEvent => new SubscribeResponse(),
                        $event instanceof RPCEvent => new RPCResponse(),
                        default => throw new NoCentrifugoResponseProvidedException(sprintf('No supported default response found for request type: %s', $request::class)),
                    };

                    $request->respond($response);
                }

                $this->eventDispatcher->dispatch(new AfterRespondEvent());

            } catch (\Throwable $throwable) {
                $this->sentryHubInterface?->captureException($throwable);
                $request->error(500, (string)$throwable);
            } finally {
                $result = $this->sentryHubInterface?->getClient()?->flush();

                // sentry v4 compatibility
                if($result instanceof PromiseInterface) {
                    $result->wait(false);
                }
                $this->sentryHubInterface?->popScope();
            }
        }
    }
}
