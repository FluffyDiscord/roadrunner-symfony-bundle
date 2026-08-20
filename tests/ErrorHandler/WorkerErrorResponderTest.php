<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\ErrorHandler;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\WorkerErrorResponder;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface as RrWorkerInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;

class FailingRendererResponder extends WorkerErrorResponder
{
    protected function renderHtmlError(\Throwable $throwable): FlattenException
    {
        throw new \RuntimeException('simulated renderer failure');
    }
}

/**
 * @see docs/specs/graceful-error-handling.md §6.9 (TC-D01..TC-D04)
 */
#[AllowMockObjectsWithoutExpectations]
class WorkerErrorResponderTest extends BaseTestCase
{
    private PSR7Worker&MockObject $psr7Worker;
    private RrWorkerInterface&MockObject $rrWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->psr7Worker = $this->createMock(PSR7Worker::class);
        $this->rrWorker = $this->createMock(RrWorkerInterface::class);
        $this->psr7Worker->method('getWorker')->willReturn($this->rrWorker);
    }

    /** TC-D01 */
    public function testDebugSendsSymfonyPageOnce(): void
    {
        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && str_contains((string)$response->getBody(), 'RuntimeException')
                    && str_contains((string)$response->getBody(), 'boot exploded'),
            ))
        ;
        $this->rrWorker->expects($this->never())->method('error');

        new WorkerErrorResponder(true)->sendThrowableResponse($this->psr7Worker, new \RuntimeException('boot exploded'));
    }

    /** TC-D02 */
    public function testDebugFallsBackToMinimalPageWhenRendererFails(): void
    {
        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && $response->getHeaderLine('Content-Type') === 'text/html; charset=utf-8'
                    && str_contains((string)$response->getBody(), 'Internal Server Error'),
            ))
        ;
        $this->rrWorker->expects($this->never())->method('error');

        new FailingRendererResponder(true)->sendThrowableResponse($this->psr7Worker, new \RuntimeException('boot exploded'));
    }

    /** TC-D03 */
    public function testProductionSendsBareFiveHundred(): void
    {
        $this->psr7Worker
            ->expects($this->once())
            ->method('respond')
            ->with($this->callback(
                static fn($response): bool => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR
                    && (string)$response->getBody() === '',
            ))
        ;

        new WorkerErrorResponder(false)->sendThrowableResponse($this->psr7Worker, new \RuntimeException('boot exploded'));
    }

    /** TC-D04 */
    public function testFallsBackToErrorFrameWhenRespondThrows(): void
    {
        $this->psr7Worker->method('respond')->willThrowException(new \RuntimeException('relay is gone'));
        $this->rrWorker->expects($this->once())->method('error');

        new WorkerErrorResponder(false)->sendThrowableResponse($this->psr7Worker, new \RuntimeException('boot exploded'));
    }

    /** TC-D04 edge: error() throwing too must not escape. */
    public function testNothingEscapesWhenErrorFrameAlsoThrows(): void
    {
        $this->psr7Worker->method('respond')->willThrowException(new \RuntimeException('relay is gone'));
        $this->rrWorker->method('error')->willThrowException(new \RuntimeException('relay is really gone'));

        new WorkerErrorResponder(false)->sendThrowableResponse($this->psr7Worker, new \RuntimeException('boot exploded'));

        $this->assertTrue(true);
    }
}
