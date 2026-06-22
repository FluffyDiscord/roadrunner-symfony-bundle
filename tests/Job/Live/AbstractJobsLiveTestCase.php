<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Live;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;

/**
 * Shared environment + client helpers for the live Jobs tests. These run against a REAL RoadRunner
 * server (http + jobs pools) and are SKIPPED unless RR_JOBS_LIVE=1, so the standard
 * `php vendor/bin/phpunit tests` run stays green. The Docker harness tests/docker-validate-jobs.sh
 * provisions the server and invokes `phpunit --group jobs-live` inside the container.
 */
abstract class AbstractJobsLiveTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!self::liveEnabled()) {
            $this->markTestSkipped('Live Jobs tests require RR_JOBS_LIVE=1 with a provisioned RoadRunner http+jobs pool. See tests/docker-validate-jobs.sh and docs/specs/rr-jobs-worker.md §N-2.');
        }
    }

    protected static function liveEnabled(): bool
    {
        return ($_SERVER['RR_JOBS_LIVE'] ?? $_ENV['RR_JOBS_LIVE'] ?? getenv('RR_JOBS_LIVE')) === '1';
    }

    protected static function varDir(): string
    {
        $dir = $_SERVER['JOBS_VAR_DIR'] ?? $_ENV['JOBS_VAR_DIR'] ?? getenv('JOBS_VAR_DIR');

        return is_string($dir) && $dir !== '' ? $dir : '/app/var';
    }

    protected static function httpBase(): string
    {
        $base = $_SERVER['JOBS_HTTP_BASE'] ?? $_ENV['JOBS_HTTP_BASE'] ?? getenv('JOBS_HTTP_BASE');

        return is_string($base) && $base !== '' ? $base : 'http://127.0.0.1:8080';
    }

    protected static function rrRpc(): string
    {
        $rpc = $_SERVER['RR_RPC'] ?? $_ENV['RR_RPC'] ?? getenv('RR_RPC');

        return is_string($rpc) && $rpc !== '' ? $rpc : 'tcp://127.0.0.1:6001';
    }

    protected static function httpGet(string $path): int
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        @file_get_contents(self::httpBase() . $path, false, $context);

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function pollJson(string $path, int $tries = 40): ?array
    {
        for ($i = 0; $i < $tries; ++$i) {
            if (is_file($path) && filesize($path) > 0) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            usleep(500_000);
        }

        return null;
    }
}
