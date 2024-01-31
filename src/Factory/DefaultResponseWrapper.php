<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Symfony\Component\HttpFoundation\Response;

class DefaultResponseWrapper
{
    public static function wrap(Response $response): string
    {
        ob_start();
        $response->sendContent();
        return ob_get_clean();
    }
}