<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * Live end-to-end client for the gRPC worker (IT-01..IT-08 in docs/specs/rr-grpc-worker.md).
 * Runs only inside the docker-validate-grpc.sh / docker-validate-all.sh containers: gated by
 * RR_GRPC_LIVE=1 (basic flows) and RR_GRPC_LIVE_FULL=1 (boot-failure flag flips, profiler,
 * required-auth — needs the dedicated harness's pool recycling and APP_DEBUG=1); drives the
 * running RR grpc pool with grpcurl.
 *
 * The server-side fixtures live in the container app (see the harness script): an App\GrpcEchoService
 * implementing the committed tests/Grpc/Live/Generated/EchoInterface contract, a marker-file event
 * listener, a test AccessTokenHandlerInterface accepting "live-token" for user "alice", and the
 * var/break-boot / var/break-routing / var/require-auth flag files re-read on every worker boot.
 */
#[Group('grpc-live')]
class GrpcLiveTest extends BaseTestCase
{
    private const PROTO_DIR = __DIR__ . '/proto';
    private const EVENT_MARKER_FILE = '/tmp/grpc-live-events.log';

    private static string $address = '127.0.0.1:9001';

    public static function setUpBeforeClass(): void
    {
        if (getenv('RR_GRPC_LIVE') !== '1') {
            self::markTestSkipped('RR_GRPC_LIVE=1 not set; run via tests/docker-validate-grpc.sh');
        }

        $address = getenv('RR_GRPC_ADDRESS');

        if (is_string($address) && $address !== '') {
            self::$address = $address;
        }
    }

    /**
     * @param list<string> $extraArguments
     * @return array{int, string}
     */
    private function grpcurl(string $method, ?string $jsonBody = null, array $extraArguments = []): array
    {
        $command = array_merge(
            ['grpcurl', '-plaintext', '-import-path', self::PROTO_DIR, '-proto', 'echo.proto'],
            $extraArguments,
        );

        if ($jsonBody !== null) {
            $command[] = '-d';
            $command[] = $jsonBody;
        }

        $command[] = self::$address;
        $command[] = $method;

        $process = new Process($command);
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput() . $process->getErrorOutput()];
    }

    /** IT-01 */
    public function testPingEchoesTheMessageWithAPidAndResponseHeader(): void
    {
        [$exitCode, $output] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"hi"}', ['-v']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('"message": "hi"', $output);
        self::assertMatchesRegularExpression('/"pid":\s*"?\d+/', $output);
        self::assertStringContainsString('x-echo', $output);
    }

    /** IT-02 */
    public function testFailSurfacesTheExactGrpcStatus(): void
    {
        [$exitCode, $output] = $this->grpcurl('bundle.test.Echo/Fail', '{}');

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('InvalidArgument', $output);
        self::assertStringContainsString('boom', $output);
    }

    /** IT-03 — same worker pid before and after an unhandled exception (pool.num_workers: 1, A6) */
    public function testCrashDoesNotReplaceTheWorkerProcess(): void
    {
        [$firstExit, $firstOutput] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"a"}');
        self::assertSame(0, $firstExit, $firstOutput);
        $firstPid = $this->readPid($firstOutput);

        [$crashExit] = $this->grpcurl('bundle.test.Echo/Crash', '{}');
        self::assertNotSame(0, $crashExit);

        [$secondExit, $secondOutput] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"b"}');
        self::assertSame(0, $secondExit, $secondOutput);

        self::assertSame($firstPid, $this->readPid($secondOutput), 'an unhandled \Exception must not cost the worker process');
    }

    /** IT-04 — events fire with the caller's metadata visible */
    public function testEventsFireWithMetadata(): void
    {
        @unlink(self::EVENT_MARKER_FILE);

        [$exitCode, $output] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"evt"}', ['-H', 'x-test: 1']);
        self::assertSame(0, $exitCode, $output);

        $marker = $this->waitForFile(self::EVENT_MARKER_FILE);
        self::assertStringContainsString('GrpcCallReceivedEvent', $marker);
        self::assertStringContainsString('GrpcCallCompletedEvent', $marker);
        self::assertStringContainsString('x-test', $marker);
    }

    /** IT-05 — both boot-failure sites answer UNAVAILABLE, and boot is retried once the flag clears */
    public function testBootFailuresAnswerUnavailableAndRecover(): void
    {
        $this->requireFullHarness();
        foreach (['break-boot', 'break-routing'] as $flag) {
            $this->setFlag($flag, true);

            try {
                $this->recyclePool();
                [$exitCode, $output] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"x"}');
                self::assertNotSame(0, $exitCode, "$flag: " . $output);
                self::assertStringContainsString('Unavailable', $output, "$flag: " . $output);
                self::assertStringContainsString('Worker boot failed', $output, "$flag: " . $output);
            } finally {
                $this->setFlag($flag, false);
            }

            $this->recyclePool();
            $recovered = $this->pingUntilOk();
            self::assertTrue($recovered, "$flag: a fresh worker must retry boot and serve OK again");
        }
    }

    /** IT-08 — security: guard, invalid token, valid token, required-mode */
    public function testSecurityFlows(): void
    {
        [$anonymousExit, $anonymousOutput] = $this->grpcurl('bundle.test.Echo/WhoAmI', '{}');
        self::assertNotSame(0, $anonymousExit);
        self::assertStringContainsString('Unauthenticated', $anonymousOutput);
        self::assertStringContainsString('Authentication required', $anonymousOutput);

        [$wrongExit, $wrongOutput] = $this->grpcurl('bundle.test.Echo/WhoAmI', '{}', ['-H', 'authorization: Bearer wrong']);
        self::assertNotSame(0, $wrongExit);
        self::assertStringContainsString('Invalid credentials', $wrongOutput);
        self::assertStringNotContainsString('secret', $wrongOutput);

        [$validExit, $validOutput] = $this->grpcurl('bundle.test.Echo/WhoAmI', '{}', ['-H', 'authorization: Bearer live-token']);
        self::assertSame(0, $validExit, $validOutput);
        self::assertStringContainsString('"user": "alice"', $validOutput);

        [$pingExit, $pingOutput] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"authed"}', ['-H', 'authorization: Bearer live-token']);
        self::assertSame(0, $pingExit, $pingOutput);

        if (getenv('RR_GRPC_LIVE_FULL') !== '1') {
            return;
        }

        $this->setFlag('require-auth', true);

        try {
            $this->recyclePool();
            [$requiredExit, $requiredOutput] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"x"}');
            self::assertNotSame(0, $requiredExit);
            self::assertStringContainsString('Missing credentials', $requiredOutput);
        } finally {
            $this->setFlag('require-auth', false);
            $this->recyclePool();
            $this->pingUntilOk();
        }
    }

    /** IT-07 — a full Symfony profile per call, with the handler's log line and dump captured */
    public function testFullProfileIsWrittenPerCall(): void
    {
        $this->requireFullHarness();
        $profilerDir = getenv('RR_GRPC_PROFILER_DIR');

        if (!is_string($profilerDir) || $profilerDir === '') {
            self::markTestSkipped('RR_GRPC_PROFILER_DIR not set');
        }

        [$exitCode, $output] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"profiled"}');
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('"message": "profiled"', $output, 'the response must stay well-formed despite dump() in the handler');

        $csvIndex = $this->waitForFile($profilerDir . '/index.csv');
        self::assertStringContainsString('grpc://bundle.test.Echo/Ping', $csvIndex);
        self::assertStringContainsString('GRPC', $csvIndex);
    }

    private function requireFullHarness(): void
    {
        if (getenv('RR_GRPC_LIVE_FULL') !== '1') {
            self::markTestSkipped('needs the dedicated harness (flag files + pool recycling): tests/docker-validate-grpc.sh');
        }
    }

    private function readPid(string $grpcurlOutput): int
    {
        $matched = preg_match('/"pid":\s*"?(\d+)/', $grpcurlOutput, $matches);
        self::assertSame(1, $matched, $grpcurlOutput);

        return (int) $matches[1];
    }

    private function setFlag(string $flag, bool $on): void
    {
        $flagDirectory = getenv('RR_GRPC_FLAG_DIR');
        self::assertIsString($flagDirectory);
        $pathname = $flagDirectory . '/' . $flag;

        if ($on) {
            file_put_contents($pathname, '1');

            return;
        }

        @unlink($pathname);
    }

    private function recyclePool(): void
    {
        $recycleCommand = getenv('RR_GRPC_RECYCLE_CMD');
        self::assertIsString($recycleCommand, 'RR_GRPC_RECYCLE_CMD must point at an rr reset command');

        $process = Process::fromShellCommandline($recycleCommand);
        $process->setTimeout(300);
        $process->run();
        usleep(500_000);
    }

    private function pingUntilOk(int $attempts = 20): bool
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            [$exitCode] = $this->grpcurl('bundle.test.Echo/Ping', '{"message":"retry"}');

            if ($exitCode === 0) {
                return true;
            }

            usleep(500_000);
        }

        return false;
    }

    private function waitForFile(string $pathname, int $attempts = 20): string
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $content = @file_get_contents($pathname);

            if ($content !== false && $content !== '') {
                return $content;
            }

            usleep(250_000);
        }

        self::fail('file never appeared: ' . $pathname);
    }
}
