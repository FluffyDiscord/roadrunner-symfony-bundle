<?php

namespace FluffyDiscord\RoadRunnerBundle\Profiler;

use Symfony\Component\HttpFoundation\Request;

class GrpcRequest extends Request
{
    public const VIRTUAL_TYPE = 'grpc';

    private string $serviceName = 'unknown';
    private string $methodName = '';

    public function __construct(float $startedAt)
    {
        parent::__construct(
            attributes: [
                '_virtual_type'    => self::VIRTUAL_TYPE,
                '_stopwatch_token' => bin2hex(random_bytes(3)),
            ],
            server: ['REQUEST_TIME_FLOAT' => $startedAt],
        );
    }

    public function describeCall(string $serviceName, string $methodName, string $handlerClass): void
    {
        $this->serviceName = $serviceName;
        $this->methodName = $methodName;

        if ($handlerClass !== '') {
            $this->attributes->set('_controller', $handlerClass . '::' . $methodName);
        }
    }

    public function getStopwatchToken(): ?string
    {
        $token = $this->attributes->get('_stopwatch_token');

        return is_string($token) ? $token : null;
    }

    public function getUri(): string
    {
        if ($this->methodName === '') {
            return 'grpc://' . $this->serviceName;
        }

        return 'grpc://' . $this->serviceName . '/' . $this->methodName;
    }

    public function getMethod(): string
    {
        return 'GRPC';
    }
}
