<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Client;

use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface;

/**
 * Builds the two SDK objects the autowired Temporal clients need post-construction: an
 * (optionally api-key authenticated) {@see ServiceClient} and the {@see ClientOptions}
 * carrying the namespace. The clients themselves ({@see \Temporal\Client\WorkflowClient},
 * {@see \Temporal\Client\ScheduleClient}) are built straight from their own static
 * `create()` in the service definitions.
 *
 * Note: {@see ServiceClient::create()} requires the `grpc` PHP extension; the client
 * services are lazy, so that requirement only applies once a client is actually injected.
 */
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
