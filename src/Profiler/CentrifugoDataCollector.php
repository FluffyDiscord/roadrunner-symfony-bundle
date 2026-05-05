<?php

namespace FluffyDiscord\RoadRunnerBundle\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class CentrifugoDataCollector extends DataCollector
{
    public function populate(
        string  $eventType,
        float   $durationMs,
        int     $startedAt,
        bool    $success,
        ?string $error,
    ): void {
        $this->data = [
            'event_type'  => $eventType,
            'duration_ms' => $durationMs,
            'started_at'  => $startedAt,
            'success'     => $success,
            'error'       => $error,
        ];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Data is set via populate(). This no-op prevents the profiler from
        // overwriting $this->data when processing unrelated HTTP profiles.
    }

    public function getName(): string
    {
        return 'centrifugo';
    }

    public function reset(): void
    {
        $this->data = [];
    }

    // ── accessors used in the Twig template ──────────────────────────────────

    public function hasData(): bool
    {
        return isset($this->data['event_type']);
    }

    public function getEventType(): string
    {
        return $this->data['event_type'] ?? 'Unknown';
    }

    public function getDurationMs(): float
    {
        return (float) ($this->data['duration_ms'] ?? 0.0);
    }

    public function isSuccess(): bool
    {
        return (bool) ($this->data['success'] ?? true);
    }

    public function getError(): ?string
    {
        return $this->data['error'] ?? null;
    }

    public function getStartedAt(): int
    {
        return (int) ($this->data['started_at'] ?? 0);
    }
}
