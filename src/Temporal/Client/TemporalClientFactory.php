<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Client;

use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface;

final class TemporalClientFactory
{
    public static function serviceClient(string $address, ?string $apiKey = null): ServiceClientInterface
    {
        if ($address === '') {
            throw new \InvalidArgumentException('Temporal frontend address must not be empty.');
        }

        $client = ServiceClient::create($address);

        if ($apiKey !== null && $apiKey !== '') {
            $client = $client->withAuthKey($apiKey);
        }

        return $client;
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
