<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Tracing;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\SignalWithStartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowClient\StartEvent;
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use Psr\Log\LoggerInterface;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Workflow;

class TemporalTracingListener
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
            $event->setInput($this->withCorrelationHeader($input, $correlationId));
        } catch (\Throwable $throwable) {
            $this->logFailedPropagation($throwable);
        }

        $this->logWorkflowStart($input, $correlationId);
    }

    public function onWorkflowSignalWithStart(SignalWithStartEvent $event): void
    {
        $input = $event->getInput();
        $startInput = $input->workflowStartInput;
        $correlationId = $this->correlationId();

        try {
            $event->setInput($input->with(
                workflowStartInput: $this->withCorrelationHeader($startInput, $correlationId),
            ));
        } catch (\Throwable $throwable) {
            $this->logFailedPropagation($throwable);
        }

        $this->logWorkflowStart($startInput, $correlationId);
    }

    private function withCorrelationHeader(StartInput $input, string $correlationId): StartInput
    {
        return $input->with(
            header: $input->header->withValue(self::CORRELATION_HEADER, $correlationId),
        );
    }

    private function logFailedPropagation(\Throwable $throwable): void
    {
        $this->logger?->warning('Temporal: failed to propagate correlation id into the workflow header', [
            'exception' => $throwable,
        ]);
    }

    private function logWorkflowStart(StartInput $input, string $correlationId): void
    {
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

        if ($this->logger !== null) {
            Workflow::getLogger()->debug('Temporal: executing activity', ['activity' => $type]);
        }

        if ($this->hub === null) {
            return;
        }

        $isReplaying = Workflow::isReplaying();
        if (!$isReplaying) {
            $this->breadcrumb(sprintf('Execute activity %s', $type), ['activity' => $type]);
        }
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
