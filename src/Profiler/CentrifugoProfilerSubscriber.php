<?php

namespace FluffyDiscord\RoadRunnerBundle\Profiler;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\ConnectEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubRefreshEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Contracts\Service\ResetInterface;

class CentrifugoProfilerSubscriber implements EventSubscriberInterface, ResetInterface
{
    private bool   $pendingRequest      = false;
    private bool   $isCentrifugoRequest = false;
    private bool   $responseSent        = false;
    private float  $requestStartTime    = 0.0;
    private string $currentEventType    = 'Unknown';

    public function __construct(
        private readonly CentrifugoDataCollector $dataCollector,
        private readonly ?Profiler               $profiler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Start timing as early as possible.
            WorkerRequestReceivedEvent::class => ['onRequestReceived', PHP_INT_MAX],

            // Record event type before any user listener runs.
            ConnectEvent::class               => ['onCentrifugoEvent', PHP_INT_MAX],
            PublishEvent::class               => ['onCentrifugoEvent', PHP_INT_MAX],
            RefreshEvent::class               => ['onCentrifugoEvent', PHP_INT_MAX],
            SubRefreshEvent::class            => ['onCentrifugoEvent', PHP_INT_MAX],
            SubscribeEvent::class             => ['onCentrifugoEvent', PHP_INT_MAX],
            RPCEvent::class                   => ['onCentrifugoEvent', PHP_INT_MAX],
            InvalidEvent::class               => ['onCentrifugoEvent', PHP_INT_MAX],

            // Finalise after the response has been sent.
            WorkerResponseSentEvent::class    => ['onResponseSent', PHP_INT_MIN],
        ];
    }

    /**
     * Fires at the start of every worker loop iteration.
     * Acts as a fallback failure-detector when services_resetter is null.
     */
    public function onRequestReceived(): void
    {
        if ($this->pendingRequest && $this->isCentrifugoRequest && !$this->responseSent) {
            $this->persistProfile(false, 'Request failed – no response was sent');
        }

        $this->requestStartTime    = hrtime(true) / 1e9;
        $this->pendingRequest      = true;
        $this->isCentrifugoRequest = false;
        $this->responseSent        = false;
        $this->currentEventType    = 'Unknown';
    }

    public function onCentrifugoEvent(CentrifugoEventInterface $event): void
    {
        $this->isCentrifugoRequest = true;

        $short = new \ReflectionClass($event)->getShortName();
        $this->currentEventType = str_ends_with($short, 'Event') ? substr($short, 0, -5) : $short;
    }

    public function onResponseSent(WorkerResponseSentEvent $event): void
    {
        if ($event->workerType !== Mode::MODE_CENTRIFUGE) {
            return;
        }

        $this->responseSent = true;
        $this->persistProfile(true, null);
    }

    public function reset(): void
    {
        if ($this->pendingRequest && $this->isCentrifugoRequest && !$this->responseSent) {
            $this->persistProfile(false, 'Request failed – an exception occurred');
        }

        $this->pendingRequest      = false;
        $this->isCentrifugoRequest = false;
        $this->responseSent        = false;
        $this->requestStartTime    = 0.0;
        $this->currentEventType    = 'Unknown';
    }

    private function persistProfile(bool $success, ?string $error): void
    {
        if ($this->profiler === null || !$this->isCentrifugoRequest) {
            return;
        }

        $durationMs = $this->requestStartTime > 0.0
            ? round((hrtime(true) / 1e9 - $this->requestStartTime) * 1000.0, 2)
            : 0.0;

        // Populate the data collector from our own state right before serialisation.
        // The data collector may already be reset — that's fine, we overwrite it here.
        $this->dataCollector->populate(
            $this->currentEventType,
            $durationMs,
            (int) $this->requestStartTime,
            $success,
            $error,
        );

        $token = substr(hash('xxh128', uniqid('rr_centrifugo_', true)), 0, 6);

        $profile = new Profile($token);
        $profile->setTime(time());
        $profile->setUrl('/centrifugo/' . strtolower($this->currentEventType));
        $profile->setMethod('CENTRIFUGO');
        $profile->setStatusCode($success ? 200 : 500);
        $profile->setIp('127.0.0.1');
        $profile->addCollector($this->dataCollector);

        $this->profiler->saveProfile($profile);
    }
}
