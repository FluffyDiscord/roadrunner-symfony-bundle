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
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\NoCentrifugoResponseProvidedException;
use FluffyDiscord\RoadRunnerBundle\Exception\UnsupportedCentrifugoRequestTypeException;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Payload\PublishResponse;
use RoadRunner\Centrifugo\Payload\RefreshResponse;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Payload\SubRefreshResponse;
use RoadRunner\Centrifugo\Payload\SubscribeResponse;
use RoadRunner\Centrifugo\Request;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;

readonly class CentrifugoWorker implements WorkerInterface
{
    public function __construct(
        private bool                       $lazyBoot,
        private bool                       $debug,
        private KernelInterface            $kernel,
        private RoadRunnerCentrifugoWorker $worker,
        private EventDispatcherInterface   $eventDispatcher,
        private ?ServicesResetterInterface $servicesResetter,
        private ?SentryHubInterface        $sentryHubInterface = null,
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
            $event = null;
            $hadException = false;

            try {
                $this->sentryHubInterface?->pushScope();

                $this->eventDispatcher->dispatch(new WorkerRequestReceivedEvent());

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

                /** @var CentrifugoEventInterface $processedEvent */
                $processedEvent = $this->eventDispatcher->dispatch($event);

                if(!$event instanceof InvalidEvent) {
                    $response = $processedEvent->getResponse() ?? match ($event::class) {
                        ConnectEvent::class => new ConnectResponse(),
                        PublishEvent::class => new PublishResponse(),
                        RefreshEvent::class => new RefreshResponse(),
                        SubRefreshEvent::class => new SubRefreshResponse(),
                        SubscribeEvent::class => new SubscribeResponse(),
                        RPCEvent::class => new RPCResponse(),
                        default => throw new NoCentrifugoResponseProvidedException(sprintf('No supported default response found for request type: %s', $request::class)),
                    };

                    $request->respond($response);
                }

                $this->eventDispatcher->dispatch(new WorkerResponseSentEvent(Mode::MODE_CENTRIFUGE));
            } catch (\Throwable $throwable) {
                $hadException = true;

                try {
                    $this->sentryHubInterface?->captureException($throwable);
                } catch (\Throwable) {}

                try {
                    $reason = $this->debug ? (string)$throwable : 'Unexpected system error';
                    $request->disconnect(Response::HTTP_INTERNAL_SERVER_ERROR, $reason, true);
                } catch (\Throwable) {}

                $this->worker->getWorker()->error((string)$throwable);

                if ($throwable instanceof \Error) {
                    $this->worker->getWorker()->stop();
                    continue;
                }
            } finally {
                try {
                    if ($hadException && $this->kernel instanceof RebootableInterface) {
                        $this->kernel->reboot(null);
                    }
                } catch (\Throwable $cleanupThrowable) {
                    $this->worker->getWorker()->error("Fatal worker cleanup error: " . $cleanupThrowable);
                    $this->worker->getWorker()->stop();
                } finally {
                    try {
                        $this->servicesResetter?->reset();
                    } catch (\Throwable $throwable) {
                        $this->worker->getWorker()->error((string)$throwable);
                        $this->worker->getWorker()->stop();
                    }
                }

                try {
                    $this->sentryHubInterface?->getClient()?->flush();
                } catch (\Throwable) {}
                try {
                    $this->sentryHubInterface?->popScope();
                } catch (\Throwable) {}
            }
        }
    }
}
