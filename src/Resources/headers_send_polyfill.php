<?php

function headers_send(int $statusCode = 200): int
{
    $rr = \FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::$currentHttpWorker;
    if ($rr === null || $statusCode >= 200 || \FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::$bootWarmupInProgress) {
        return $statusCode;
    }

    $response = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1]['object'] ?? null;
    if (!$response instanceof \Symfony\Component\HttpFoundation\Response) {
        return $statusCode;
    }

    /** @var array<string, array<string>> $headers */
    $headers = $response->headers->allPreserveCaseWithoutCookies();
    $unsentHeaders = \FluffyDiscord\RoadRunnerBundle\Http\InformationalHeaders::getUnsentHeaders($headers);
    if ($unsentHeaders === []) {
        return $statusCode;
    }

    $rr->respond($statusCode, '', $unsentHeaders, endOfStream: false);
    \FluffyDiscord\RoadRunnerBundle\Http\InformationalHeaders::rememberSentHeaders($unsentHeaders);

    return $statusCode;
}
