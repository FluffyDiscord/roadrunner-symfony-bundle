<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Temporal\Worker\ServiceCredentials;

class TemporalCredentialsFactory
{
    public static function create(?string $apiKey): ServiceCredentials
    {
        $credentials = ServiceCredentials::create();

        if ($apiKey !== null && $apiKey !== '') {
            $credentials = $credentials->withApiKey($apiKey);
        }

        return $credentials;
    }
}