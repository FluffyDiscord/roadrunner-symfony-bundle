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

    public function hasData(): bool
    {
        return isset($this->data['event_type']);
    }

    public function getEventType(): string
    {
        $value = $this->data['event_type'] ?? null;

        return is_string($value) ? $value : 'Unknown';
    }

    public function getDurationMs(): float
    {
        $value = $this->data['duration_ms'] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public function isSuccess(): bool
    {
        return (bool) ($this->data['success'] ?? true);
    }

    public function getError(): ?string
    {
        $value = $this->data['error'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getStartedAt(): int
    {
        $value = $this->data['started_at'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
