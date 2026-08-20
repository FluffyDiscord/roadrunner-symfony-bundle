<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcHandlerFaultException;
use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcRequestDecodingException;
use FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcAuthorizationGuard;
use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class GrpcInvoker
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?GrpcAuthorizationGuard  $authorizationGuard = null,
    )
    {
    }

    public function hasAuthorizationGuard(): bool
    {
        return $this->authorizationGuard !== null;
    }

    public function invoke(GrpcServiceRoute $route, GrpcMethodRoute $methodRoute, ContextInterface $context, string $input): string
    {
        $method = $methodRoute->method;
        $startedAt = hrtime(true);
        $request = null;

        try {
            $request = $this->decodeRequest($method, $input);

            $this->eventDispatcher->dispatch(new GrpcCallReceivedEvent($route->serviceName, $method->name, $route->service, $method, $context, $request));

            $this->authorizationGuard?->assertGranted($methodRoute, $request);

            $response = $this->callHandler($route, $method, $context, $request);
            $body = $this->encodeResponse($route, $method, $response);
        } catch (\Throwable $throwable) {
            $durationMs = $this->elapsedMilliseconds($startedAt);
            $this->eventDispatcher->dispatch(new GrpcCallFailedEvent($route->serviceName, $method->name, $context, $request, $throwable, self::classifyStatusCode($throwable), $durationMs));

            throw $throwable;
        }

        $durationMs = $this->elapsedMilliseconds($startedAt);
        $this->eventDispatcher->dispatch(new GrpcCallCompletedEvent($route->serviceName, $method->name, $context, $request, $response, $durationMs));

        return $body;
    }

    public static function classifyStatusCode(\Throwable $throwable): int
    {
        if ($throwable instanceof GRPCExceptionInterface) {
            return $throwable->getCode();
        }

        return StatusCode::UNKNOWN;
    }

    private function decodeRequest(Method $method, string $input): Message
    {
        $requestClass = $method->inputType;

        try {
            $request = new $requestClass();

            if ($input !== '') {
                $request->mergeFromString($input);
            }
        } catch (\Throwable $decodingFailure) {
            throw GrpcRequestDecodingException::create($decodingFailure->getMessage(), StatusCode::INTERNAL, $decodingFailure);
        }

        if (!$request instanceof Message) {
            throw GrpcHandlerFaultException::create(sprintf('Input type %s of %s() is not a protobuf message', $requestClass, $method->name), StatusCode::INTERNAL);
        }

        return $request;
    }

    private function callHandler(GrpcServiceRoute $route, Method $method, ContextInterface $context, Message $request): Message
    {
        $handlerCallable = [$route->service, $method->name];

        if (!is_callable($handlerCallable)) {
            throw GrpcHandlerFaultException::create(sprintf('%s::%s() is not callable', $route->service::class, $method->name), StatusCode::INTERNAL);
        }

        $response = $handlerCallable($context, $request);
        $expectedType = $method->outputType;

        if (!$response instanceof $expectedType || !$response instanceof Message) {
            throw GrpcHandlerFaultException::create(sprintf('%s::%s() must return %s, got %s', $route->service::class, $method->name, $expectedType, get_debug_type($response)), StatusCode::INTERNAL);
        }

        return $response;
    }

    private function encodeResponse(GrpcServiceRoute $route, Method $method, Message $response): string
    {
        try {
            return $response->serializeToString();
        } catch (\Throwable $serializationFailure) {
            throw GrpcHandlerFaultException::create(sprintf('%s::%s() response could not be serialized: %s', $route->service::class, $method->name, $serializationFailure->getMessage()), StatusCode::INTERNAL, $serializationFailure);
        }
    }

    private function elapsedMilliseconds(int|float $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1e6, 3);
    }
}
