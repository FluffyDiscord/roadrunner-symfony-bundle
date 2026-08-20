<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Tracing;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use Psr\Log\LoggerInterface;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface as SentryHubInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;

class GrpcTracingListener
{
    public function __construct(
        private readonly ?LoggerInterface    $logger,
        private readonly ?SentryHubInterface $sentryHub,
    )
    {
    }

    public function onCallReceived(GrpcCallReceivedEvent $event): void
    {
        $context = [
            'service'       => $event->serviceName,
            'method'        => $event->methodName,
            'metadata_keys' => $this->readMetadataKeys($event->context),
        ];

        $this->logger?->info('gRPC call received', $context);
        $this->addBreadcrumb(Breadcrumb::LEVEL_INFO, 'gRPC call received', $context);
    }

    public function onCallCompleted(GrpcCallCompletedEvent $event): void
    {
        $context = [
            'service'     => $event->serviceName,
            'method'      => $event->methodName,
            'duration_ms' => $event->durationMs,
        ];

        $this->logger?->info('gRPC call completed', $context);
        $this->addBreadcrumb(Breadcrumb::LEVEL_INFO, 'gRPC call completed', $context);
    }

    public function onCallFailed(GrpcCallFailedEvent $event): void
    {
        $context = [
            'service'            => $event->serviceName,
            'method'             => $event->methodName,
            'worker_status_code' => $event->workerStatusCode,
            'duration_ms'        => $event->durationMs,
            'exception'          => $event->throwable::class . ': ' . $event->throwable->getMessage(),
        ];

        $this->logger?->warning('gRPC call failed', $context);
        $this->addBreadcrumb(Breadcrumb::LEVEL_WARNING, 'gRPC call failed', $context);
    }

    /**
     * @return list<string>
     */
    private function readMetadataKeys(ContextInterface $context): array
    {
        $metadata = $context->getValue(GrpcMetadata::class);

        if (!$metadata instanceof GrpcMetadata) {
            return [];
        }

        return $metadata->getKeys();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function addBreadcrumb(string $level, string $message, array $context): void
    {
        if ($this->sentryHub === null) {
            return;
        }

        $this->sentryHub->addBreadcrumb(new Breadcrumb($level, Breadcrumb::TYPE_DEFAULT, 'grpc', $message, $context));
    }
}
