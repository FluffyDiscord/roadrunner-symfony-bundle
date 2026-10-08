<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Doctrine\Live;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('doctrine-preconnect-live')]
class DoctrinePreconnectLiveTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DOCTRINE_PRECONNECT_LIVE') !== '1') {
            self::markTestSkipped('Runs only inside tests/docker-validate-doctrine-preconnect.sh (needs a live RoadRunner + PostgreSQL).');
        }
    }

    public function testOnlyTheNamedConnectionIsPreconnectedAtBoot(): void
    {
        $base = getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8080';

        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $raw = file_get_contents($base . '/db-status', false, $context);

        self::assertNotFalse($raw, 'No response from /db-status');
        $data = json_decode((string) $raw, true);
        self::assertIsArray($data, 'Non-JSON response from /db-status: ' . $raw);
        self::assertArrayHasKey('default', $data);
        self::assertArrayHasKey('secondary', $data);

        self::assertStringContainsStringIgnoringCase('pgsql', (string) $data['default']['driver'], 'default must use the PostgreSQL driver');
        self::assertTrue($data['default']['connected'], 'PostgreSQL connection must be preconnected at worker boot, before the first request');

        self::assertStringContainsStringIgnoringCase('pgsql', (string) $data['secondary']['driver'], 'secondary must use the PostgreSQL driver');
        self::assertFalse($data['secondary']['connected'], 'connection left out of doctrine.preconnect must NOT be preconnected');

        self::assertArrayHasKey('tertiary', $data);
        self::assertStringContainsStringIgnoringCase('sqlite', (string) $data['tertiary']['driver'], 'tertiary must use the sqlite driver');
        self::assertFalse($data['tertiary']['connected'], 'non-PostgreSQL connection must NOT be preconnected, even when named');
    }
}
