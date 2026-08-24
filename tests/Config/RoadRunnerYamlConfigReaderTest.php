<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Config;

use FluffyDiscord\RoadRunnerBundle\Config\RoadRunnerYamlConfigReader;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;

/** §4.17 */
class RoadRunnerYamlConfigReaderTest extends BaseTestCase
{
    private function makeReader(): RoadRunnerYamlConfigReader
    {
        return new RoadRunnerYamlConfigReader(__DIR__ . '/../Grpc/Fixtures/config', 'grpc.rr.yaml');
    }

    public function testReadsTheGrpcSection(): void
    {
        $section = $this->makeReader()->getSection('grpc');

        self::assertNotNull($section);
        self::assertSame('tcp://127.0.0.1:9001', $section['listen']);
        self::assertSame(['echo.proto'], $section['proto']);
    }

    public function testEnvironmentPlaceholdersAreExpanded(): void
    {
        putenv('GRPC_TLS_CERT=/etc/certs/server.pem');

        try {
            $section = $this->makeReader()->getSection('grpc');

            self::assertNotNull($section);
            self::assertIsArray($section['tls']);
            self::assertSame('/etc/certs/server.pem', $section['tls']['cert']);
        } finally {
            putenv('GRPC_TLS_CERT');
        }
    }

    public function testUnsetPlaceholderFallsBackToItsDefault(): void
    {
        putenv('GRPC_TLS_CERT');

        $section = $this->makeReader()->getSection('grpc');

        self::assertNotNull($section);
        self::assertIsArray($section['tls']);
        self::assertSame('', $section['tls']['cert']);
    }

    public function testMissingFileYieldsNull(): void
    {
        $reader = new RoadRunnerYamlConfigReader(__DIR__, 'nope.yaml');

        self::assertNull($reader->getSection('grpc'));
    }

    public function testNullPathYieldsNull(): void
    {
        $reader = new RoadRunnerYamlConfigReader(__DIR__, null);

        self::assertNull($reader->getSection('grpc'));
    }

    public function testMissingSectionYieldsNull(): void
    {
        self::assertNull($this->makeReader()->getSection('jobs'));
    }
}
