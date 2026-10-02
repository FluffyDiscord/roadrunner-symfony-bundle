<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Client;

use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Internal\Support\DateInterval;

final class TemporalClientFactory
{
    /**
     * @param int<0, max> $rpcMaxAttempts
     */
    public static function serviceClient(string $address, ?string $apiKey, float $rpcTimeoutSeconds, int $rpcMaxAttempts): ServiceClient
    {
        if ($address === '') {
            throw new \InvalidArgumentException('Temporal frontend address must not be empty.');
        }

        $client = ServiceClient::create($address)->withContext(self::createContext($rpcTimeoutSeconds, $rpcMaxAttempts));

        if ($apiKey !== null && $apiKey !== '') {
            $client = $client->withAuthKey($apiKey);
        }

        return $client;
    }

    /**
     * @param int<0, max> $rpcMaxAttempts
     */
    public static function createContext(float $rpcTimeoutSeconds, int $rpcMaxAttempts): ContextInterface
    {
        $context = Context::default();
        $rpcTimeoutMilliseconds = (int) round($rpcTimeoutSeconds * 1000);
        $retryOptions = $context->getRetryOptions()->withMaximumAttempts($rpcMaxAttempts);

        return $context
            ->withTimeout($rpcTimeoutMilliseconds, DateInterval::FORMAT_MILLISECONDS)
            ->withRetryOptions($retryOptions);
    }

    public static function clientOptions(string $namespace): ClientOptions
    {
        $options = new ClientOptions();

        if ($namespace !== '') {
            $options = $options->withNamespace($namespace);
        }

        return $options;
    }
}
