<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\Payload;

/** TC-15 + the TC-10 edge (registered closure fires while a frame is unanswered) */
#[AllowMockObjectsWithoutExpectations]
class GrpcWorkerShutdownTest extends AbstractGrpcWorkerTestCase
{
    public function testShutdownDuringAnUnansweredFrameSendsAnErrorWithTheCallLabel(): void
    {
        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);
        $this->rrWorker->method('respond')->willReturnCallback(function () use ($worker): void {
            $worker->callHandleShutdown(['message' => 'Allowed memory size of 1 bytes exhausted', 'file' => 'x.php', 'line' => 1]);
            throw new \RuntimeException('simulated death mid-respond');
        });

        $errors = [];
        $this->rrWorker->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $worker->start();

        self::assertCount(1, $errors);
        self::assertStringContainsString('Worker terminated during gRPC call bundle.test.Echo/Ping', $errors[0]);
        self::assertStringNotContainsString('Allowed memory size', $errors[0]);
        self::assertStringContainsString('fatal: Allowed memory size', implode("\n", $worker->loggedErrors));
    }

    public function testShutdownInDebugSendsTheFatalReasonToTheClient(): void
    {
        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')], debug: true);
        $this->rrWorker->method('respond')->willReturnCallback(function () use ($worker): void {
            $worker->callHandleShutdown(['message' => 'Allowed memory size of 1 bytes exhausted', 'file' => 'x.php', 'line' => 1]);
            throw new \RuntimeException('simulated death mid-respond');
        });

        $errors = [];
        $this->rrWorker->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $worker->start();

        self::assertCount(1, $errors);
        self::assertStringContainsString('Worker terminated during gRPC call bundle.test.Echo/Ping', $errors[0]);
        self::assertStringContainsString('Allowed memory size', $errors[0]);
    }

    public function testShutdownAfterARespondedFrameIsANoOp(): void
    {
        $worker = $this->makeWorker([$this->makeFramePayload('bundle.test.Echo', 'Ping')]);

        $worker->start();

        $this->rrWorker->expects($this->never())->method('error');

        $worker->callHandleShutdown(['message' => 'fatal']);

        self::assertSame([], array_filter($worker->loggedErrors, static fn (string $line): bool => str_contains($line, 'fatal')));
    }

    public function testShutdownOutsideAFrameIsANoOp(): void
    {
        $worker = $this->makeWorker([]);
        $worker->start();

        $this->rrWorker->expects($this->never())->method('error');

        $worker->callHandleShutdown(null);
    }

    public function testShutdownIsRegisteredOnceViaTheSeam(): void
    {
        $worker = $this->makeWorker([
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
            $this->makeFramePayload('bundle.test.Echo', 'Ping'),
        ]);

        $worker->start();

        self::assertSame(1, $worker->shutdownRegistrations);
        self::assertNotNull($worker->registeredShutdown);
    }
}
