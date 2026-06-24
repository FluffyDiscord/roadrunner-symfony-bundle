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

    /** @var array<array<string>> $headers */
    $headers = $response->headers->allPreserveCaseWithoutCookies();
    if ($headers === []) {
        return $statusCode;
    }

    $rr->respond($statusCode, '', $headers, endOfStream: false);

    return $statusCode;
}
