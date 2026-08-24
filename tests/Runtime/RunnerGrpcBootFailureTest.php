<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Runtime;

use FluffyDiscord\RoadRunnerBundle\Runtime\Runner;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Google\Rpc\Status;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\GRPC\StatusCode;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class TestableGrpcRunner extends Runner
{
    /** @var list<string> */
    public array $loggedErrors = [];

    public function __construct(KernelInterface $kernel, string $mode, private readonly RrWorkerInterface $fallbackWorker)
    {
        parent::__construct($kernel, $mode, 'worker=1');
    }

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function createFallbackRoadRunnerWorker(): RrWorkerInterface
    {
        return $this->fallbackWorker;
    }
}

/** TC-18 */
#[AllowMockObjectsWithoutExpectations]
class RunnerGrpcBootFailureTest extends BaseTestCase
{
    public function testGrpcModeAnswersOnePayloadWithUnavailableAndReturnsOne(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('boot')->willThrowException(new \RuntimeException('container exploded'));
        $kernel->method('isDebug')->willReturn(false);

        $fallbackWorker = $this->createMock(RrWorkerInterface::class);
        $fallbackWorker->method('waitPayload')->willReturn(new Payload('', '{"service":"s","method":"m","context":{}}'));

        $responses = [];
        $fallbackWorker->method('respond')->willReturnCallback(static function (Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });

        $runner = new TestableGrpcRunner($kernel, Mode::MODE_GRPC, $fallbackWorker);

        self::assertSame(1, $runner->run());
        self::assertCount(1, $responses);

        $document = json_decode($responses[0]->header, true);
        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::UNAVAILABLE, $status->getCode());
        self::assertSame('Worker boot failed', $status->getMessage());
    }

    public function testGrpcModeDebugMessageCarriesTheThrowable(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('boot')->willThrowException(new \RuntimeException('container exploded'));
        $kernel->method('isDebug')->willReturn(true);

        $fallbackWorker = $this->createMock(RrWorkerInterface::class);
        $fallbackWorker->method('waitPayload')->willReturn(new Payload('', '{}'));

        $responses = [];
        $fallbackWorker->method('respond')->willReturnCallback(static function (Payload $payload) use (&$responses): void {
            $responses[] = $payload;
        });

        new TestableGrpcRunner($kernel, Mode::MODE_GRPC, $fallbackWorker)->run();

        $document = json_decode($responses[0]->header, true);
        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertStringContainsString('container exploded', $status->getMessage());
    }

    public function testJobsModeStillReturnsOneWithoutResponding(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('boot')->willThrowException(new \RuntimeException('container exploded'));

        $fallbackWorker = $this->createMock(RrWorkerInterface::class);
        $fallbackWorker->expects($this->never())->method('respond');

        $runner = new TestableGrpcRunner($kernel, Mode::MODE_JOBS, $fallbackWorker);

        self::assertSame(1, $runner->run());
    }
}
