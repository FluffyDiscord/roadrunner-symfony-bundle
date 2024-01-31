<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Basically a copy of BinaryFileResponse->sendContent()
 * but yielding to behave like a generator
 */
class StreamedResponseWrapper
{
    public static function wrap(StreamedResponse $response): \Generator
    {
        $kernelCallback = $response->getCallback();

        $kernelCallbackRef = new \ReflectionFunction($kernelCallback);
        $closureVars = $kernelCallbackRef->getClosureUsedVariables();

        // was not wrapped in Kernel
        if (!isset($closureVars["callback"])) {
            $closureVars["callback"] = $response->getCallback();
        }

        $ref = new \ReflectionFunction($closureVars["callback"]);
        if (!$ref->isGenerator()) {
            yield DefaultResponseWrapper::wrap($response);
            return;
        }

        $request = $closureVars["request"] ?? null;
        assert($request === null || $request instanceof Request);

        $requestStack = $closureVars["requestStack"] ?? null;
        assert($requestStack === null || $requestStack instanceof RequestStack);

        // simulate Kernel wrap
        try {
            $requestStack?->push($request);

            foreach ($closureVars["callback"]() as $output) {
                try {
                    yield $output;
                } catch (StreamStoppedException) {
                    break;
                }
            }
        } finally {
            $requestStack?->pop();
        }
    }
}