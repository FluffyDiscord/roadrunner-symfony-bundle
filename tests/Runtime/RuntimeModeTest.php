<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Runtime;

use FluffyDiscord\RoadRunnerBundle\Runtime\Runtime;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;

/** TC-25 */
class RuntimeModeTest extends BaseTestCase
{
    private function resolveRuntimeMode(string $rrMode, string $rrConfigContent): string
    {
        $projectDir = sys_get_temp_dir() . '/rr-bundle-runtime-mode-' . bin2hex(random_bytes(4));
        mkdir($projectDir);
        file_put_contents($projectDir . '/.rr.yaml', $rrConfigContent);

        try {
            $reflection = new \ReflectionClass(Runtime::class);
            $runtime = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('rrConfigPath')->setValue($runtime, '.rr.yaml');
            $reflection->getProperty('projectDir')->setValue($runtime, $projectDir);
            $method = new \ReflectionMethod(Runtime::class, 'resolveRuntimeMode');

            $resolved = $method->invoke($runtime, $rrMode);
            self::assertIsString($resolved);

            return $resolved;
        } finally {
            unlink($projectDir . '/.rr.yaml');
            rmdir($projectDir);
        }
    }

    public function testGrpcPoolDebugYieldsWorkerZero(): void
    {
        self::assertSame('worker=0', $this->resolveRuntimeMode('grpc', "grpc:\n  pool:\n    debug: true\n"));
    }

    public function testGrpcWithoutPoolDebugYieldsWorkerOne(): void
    {
        self::assertSame('worker=1', $this->resolveRuntimeMode('grpc', "grpc:\n  pool:\n    debug: false\n"));
    }

    public function testGrpcWithoutAGrpcSectionYieldsWorkerOne(): void
    {
        self::assertSame('worker=1', $this->resolveRuntimeMode('grpc', "http: ~\n"));
    }
}
