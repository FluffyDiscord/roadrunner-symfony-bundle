<?php

namespace FluffyDiscord\RoadRunnerBundle\Profiler;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\EnvironmentInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

class GrpcProfilerSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?GrpcRequest $currentRequest = null;
    private bool $requestPushed = false;
    private float $startedAt = 0.0;
    private ?\Throwable $failure = null;
    private int $workerStatusCode = StatusCode::OK;

    /**
     * @param list<string> $redactedMetadataKeys
     */
    public function __construct(
        private readonly GrpcDataCollector      $dataCollector,
        private readonly ?Profiler              $profiler,
        private readonly ?RequestStack          $virtualRequestStack,
        private readonly ?Stopwatch             $stopwatch,
        private readonly ?TokenStorageInterface $tokenStorage,
        private readonly EnvironmentInterface   $environment,
        private readonly array                  $redactedMetadataKeys,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRequestReceivedEvent::class => ['onRequestReceived', PHP_INT_MAX],
            GrpcCallReceivedEvent::class      => ['onCallReceived', PHP_INT_MAX],
            GrpcCallCompletedEvent::class     => ['onCallCompleted', PHP_INT_MAX],
            GrpcCallFailedEvent::class        => ['onCallFailed', PHP_INT_MAX],
            WorkerResponseSentEvent::class    => ['onResponseSent', PHP_INT_MIN],
        ];
    }

    public function onRequestReceived(): void
    {
        if (!$this->isGrpcWorker()) {
            return;
        }

        $this->finishAbandonedFrame();

        $this->startedAt = microtime(true);
        $this->failure = null;
        $this->workerStatusCode = StatusCode::OK;
        $this->currentRequest = new GrpcRequest($this->startedAt);
        $this->dataCollector->reset();

        $this->virtualRequestStack?->push($this->currentRequest);
        $this->requestPushed = $this->virtualRequestStack !== null;
        $this->stopwatch?->openSection();
    }

    public function onCallReceived(GrpcCallReceivedEvent $event): void
    {
        if (!$this->isGrpcWorker()) {
            return;
        }

        $this->currentRequest?->describeCall($event->serviceName, $event->methodName, $event->service::class);

        $this->dataCollector->populateCall(
            $event->serviceName,
            $event->methodName,
            $event->service::class,
            $this->encodeMessage($event->request),
            $this->redactMetadata($event->context),
            $this->readAuthenticatedUser(),
        );
    }

    public function onCallCompleted(GrpcCallCompletedEvent $event): void
    {
        if (!$this->isGrpcWorker()) {
            return;
        }

        $this->workerStatusCode = StatusCode::OK;
        $this->dataCollector->populateOutcome(true, StatusCode::OK, $this->encodeMessage($event->response), null, $event->durationMs, (int)$this->startedAt);
    }

    public function onCallFailed(GrpcCallFailedEvent $event): void
    {
        if (!$this->isGrpcWorker()) {
            return;
        }

        $this->failure = $event->throwable;
        $this->workerStatusCode = $event->workerStatusCode;

        $hasCallData = $this->dataCollector->hasData();

        if (!$hasCallData) {
            $this->currentRequest?->describeCall($event->serviceName, $event->methodName, '');
            $this->dataCollector->populateCall(
                $event->serviceName,
                $event->methodName,
                '',
                $event->request === null ? null : $this->encodeMessage($event->request),
                $event->context === null ? [] : $this->redactMetadata($event->context),
                $this->readAuthenticatedUser(),
            );
        }

        $error = $event->throwable::class . ': ' . $event->throwable->getMessage();
        $this->dataCollector->populateOutcome(false, $event->workerStatusCode, null, $error, $event->durationMs, (int)$this->startedAt);
    }

    public function onResponseSent(WorkerResponseSentEvent $event): void
    {
        if ($event->workerType !== Mode::MODE_GRPC || !$this->isGrpcWorker()) {
            return;
        }

        $this->finishFrame($this->failure);
    }

    public function reset(): void
    {
        if (!$this->isGrpcWorker()) {
            return;
        }

        $this->finishAbandonedFrame();
    }

    private function isGrpcWorker(): bool
    {
        return $this->environment->getMode() === Mode::MODE_GRPC;
    }

    private function finishAbandonedFrame(): void
    {
        if ($this->currentRequest === null) {
            return;
        }

        $abandonedFailure = $this->failure ?? new \RuntimeException('Call failed – no response was sent');

        if ($this->failure === null) {
            $this->workerStatusCode = StatusCode::UNKNOWN;
            $durationMs = round((microtime(true) - $this->startedAt) * 1000.0, 3);
            $this->dataCollector->populateOutcome(false, StatusCode::UNKNOWN, null, $abandonedFailure->getMessage(), $durationMs, (int)$this->startedAt);
        }

        $this->finishFrame($abandonedFailure);
    }

    private function finishFrame(?\Throwable $throwable): void
    {
        $request = $this->currentRequest;

        if ($request === null) {
            return;
        }

        $this->stopStopwatchSection($request);
        $this->popVirtualRequest();

        $this->currentRequest = null;

        if ($this->profiler === null) {
            return;
        }

        $response = new Response('', $this->httpStatusFor($this->workerStatusCode));
        $profile = $this->profiler->collect($request, $response, $throwable);

        if ($profile === null) {
            return;
        }

        $this->profiler->saveProfile($profile);
    }

    private function stopStopwatchSection(GrpcRequest $request): void
    {
        $token = $request->getStopwatchToken();

        if ($this->stopwatch === null || $token === null) {
            return;
        }

        try {
            $this->stopwatch->stopSection($token);
        } catch (\LogicException) {
        }
    }

    private function popVirtualRequest(): void
    {
        if (!$this->requestPushed) {
            return;
        }

        $this->requestPushed = false;
        $this->virtualRequestStack?->pop();
    }

    private function httpStatusFor(int $workerStatusCode): int
    {
        if ($workerStatusCode === StatusCode::OK) {
            return Response::HTTP_OK;
        }

        $serverSideCodes = [StatusCode::INTERNAL, StatusCode::UNKNOWN, StatusCode::UNAVAILABLE, StatusCode::DATA_LOSS];
        $isServerSide = in_array($workerStatusCode, $serverSideCodes, true);

        return $isServerSide ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_BAD_REQUEST;
    }

    private function encodeMessage(Message $message): ?string
    {
        try {
            return $message->serializeToJsonString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function redactMetadata(ContextInterface $context): array
    {
        $metadata = $context->getValue(GrpcMetadata::class);

        if (!$metadata instanceof GrpcMetadata) {
            return [];
        }

        $redacted = [];

        foreach ($metadata->all() as $key => $values) {
            $redacted[$key] = $this->redactValues($key, $values);
        }

        return $redacted;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function redactValues(string $key, array $values): array
    {
        $isSensitive = in_array($key, $this->redactedMetadataKeys, true);

        if ($isSensitive) {
            return array_fill(0, count($values), GrpcDataCollector::REDACTED_VALUE);
        }

        $isBinary = str_ends_with($key, '-bin');

        if ($isBinary) {
            return array_map(static fn (string $value): string => sprintf('<binary, %d bytes>', strlen($value)), $values);
        }

        return $values;
    }

    private function readAuthenticatedUser(): ?string
    {
        $token = $this->tokenStorage?->getToken();
        $user = $token?->getUser();

        return $user?->getUserIdentifier();
    }
}
