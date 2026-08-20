<?php

namespace FluffyDiscord\RoadRunnerBundle\Profiler;

use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class GrpcDataCollector extends DataCollector
{
    public const REDACTED_VALUE = '••••••';

    /**
     * @param array<string, list<string>> $metadata
     */
    public function populateCall(string $serviceName, string $methodName, string $handlerClass, ?string $requestJson, array $metadata, ?string $authenticatedUser): void
    {
        $this->data['service_name'] = $serviceName;
        $this->data['method_name'] = $methodName;
        $this->data['handler_class'] = $handlerClass;
        $this->data['request_json'] = $requestJson;
        $this->data['metadata'] = $metadata;
        $this->data['authenticated_user'] = $authenticatedUser;
    }

    public function populateOutcome(bool $success, int $workerStatusCode, ?string $responseJson, ?string $error, float $durationMs, int $startedAt): void
    {
        $this->data['success'] = $success;
        $this->data['worker_status_code'] = $workerStatusCode;
        $this->data['worker_status_name'] = self::statusName($workerStatusCode);
        $this->data['response_json'] = $responseJson;
        $this->data['error'] = $error;
        $this->data['duration_ms'] = $durationMs;
        $this->data['started_at'] = $startedAt;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function getName(): string
    {
        return 'grpc';
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function hasData(): bool
    {
        return isset($this->data['service_name']);
    }

    public function getServiceName(): string
    {
        return $this->readString('service_name', 'unknown');
    }

    public function getMethodName(): string
    {
        return $this->readString('method_name', '');
    }

    public function getHandlerClass(): string
    {
        return $this->readString('handler_class', '');
    }

    public function getRequestJson(): ?string
    {
        return $this->readNullableString('request_json');
    }

    public function getResponseJson(): ?string
    {
        return $this->readNullableString('response_json');
    }

    /**
     * @return array<string, list<string>>
     */
    public function getMetadata(): array
    {
        $metadata = $this->data['metadata'] ?? [];

        if (!is_array($metadata)) {
            return [];
        }

        /** @var array<string, list<string>> $metadata */
        return $metadata;
    }

    public function getAuthenticatedUser(): ?string
    {
        return $this->readNullableString('authenticated_user');
    }

    public function isSuccess(): bool
    {
        return (bool)($this->data['success'] ?? true);
    }

    public function getWorkerStatusCode(): int
    {
        $value = $this->data['worker_status_code'] ?? null;

        return is_numeric($value) ? (int)$value : StatusCode::OK;
    }

    public function getWorkerStatusName(): string
    {
        return $this->readString('worker_status_name', 'OK');
    }

    public function getError(): ?string
    {
        return $this->readNullableString('error');
    }

    public function getDurationMs(): float
    {
        $value = $this->data['duration_ms'] ?? null;

        return is_numeric($value) ? (float)$value : 0.0;
    }

    public function getStartedAt(): int
    {
        $value = $this->data['started_at'] ?? null;

        return is_numeric($value) ? (int)$value : 0;
    }

    public static function statusName(int $code): string
    {
        $constants = new \ReflectionClass(StatusCode::class)->getConstants();
        $name = array_search($code, $constants, true);

        if (!is_string($name)) {
            return sprintf('UNKNOWN(%d)', $code);
        }

        return $name;
    }

    private function readString(string $key, string $default): string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    private function readNullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
