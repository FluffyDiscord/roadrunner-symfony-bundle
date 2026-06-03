<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Tracing;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\StartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use Psr\Log\LoggerInterface;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Opt-in tracing (enabled via `fluffy_discord_road_runner.temporal.tracing: true`). Logs
 * selected interceptor events on the "temporal" Monolog channel, adds Sentry breadcrumbs
 * when Sentry is present, and propagates a correlation id into started workflows so a run
 * can be tied back to the request that started it. Wired to events by the bundle extension
 * (kernel.event_listener tags) only when tracing is on, so there is zero cost otherwise.
 */
final class TemporalTracingListener
{
    public const CORRELATION_HEADER = 'x-correlation-id';

    public function __construct(
        private readonly ?LoggerInterface    $logger = null,
        private readonly ?RequestStack       $requestStack = null,
        private readonly ?SentryHubInterface $hub = null,
    )
    {
    }

    public function onWorkflowStart(StartEvent $event): void
    {
        $input = $event->getInput();
        $correlationId = $this->correlationId();

        try {
            $event->setInput($input->with(
                header: $input->header->withValue(self::CORRELATION_HEADER, $correlationId),
            ));
        } catch (\Throwable $throwable) {
            // Never break a workflow start because tracing failed: leave the input as-is.
            $this->logger?->warning('Temporal: failed to propagate correlation id into the workflow header', [
                'exception' => $throwable,
            ]);
        }

        $this->logger?->info('Temporal: starting workflow', [
            'workflowType'           => $input->workflowType,
            'workflowId'             => $input->workflowId,
            self::CORRELATION_HEADER => $correlationId,
        ]);

        $this->breadcrumb(sprintf('Start workflow %s', $input->workflowType), [
            self::CORRELATION_HEADER => $correlationId,
        ]);
    }

    public function onExecuteActivity(ExecuteActivityEvent $event): void
    {
        $type = $event->getInput()->type;

        $this->logger?->debug('Temporal: executing activity', ['activity' => $type]);
        $this->breadcrumb(sprintf('Execute activity %s', $type), ['activity' => $type]);
    }

    public function onActivityInbound(ActivityEvent $event): void
    {
        $correlationId = $event->getInput()->header->getValue(self::CORRELATION_HEADER);

        $this->logger?->debug('Temporal: activity inbound', [
            self::CORRELATION_HEADER => $correlationId,
        ]);
    }

    private function correlationId(): string
    {
        $request = $this->requestStack?->getCurrentRequest();
        $headerId = $request?->headers->get('X-Request-Id');

        if (is_string($headerId) && $headerId !== '') {
            return $headerId;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function breadcrumb(string $message, array $metadata): void
    {
        $this->hub?->addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'temporal',
            $message,
            $metadata,
        ));
    }
}
