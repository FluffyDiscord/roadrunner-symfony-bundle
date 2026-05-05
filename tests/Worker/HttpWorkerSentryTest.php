<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Sentry\ClientInterface as SentryClientInterface;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerSentryTest extends AbstractHttpWorkerTestCase
{
    public function testSentryPushAndPopScopeAroundEachRequest(): void
    {
        $sentryHub = $this->makeSentryHubMock();
        $sentryHub->expects($this->once())->method('pushScope');
        $sentryHub->expects($this->once())->method('popScope');

        $this->setupSuccessfulRequest();

        $this->makeWorker(sentryHub: $sentryHub)->start();
    }

    public function testSentryCapturesExceptionFromKernelHandle(): void
    {
        $exception = new \RuntimeException('captured');

        $sentryHub = $this->makeSentryHubMock();
        $sentryHub->expects($this->once())->method('captureException')->with($exception);

        $this->psr7Worker
            ->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null)
        ;
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());
        $this->kernel->method('handle')->willThrowException($exception);

        $this->makeWorker(sentryHub: $sentryHub)->start();
    }

    public function testSentryDoesNotCaptureExceptionOnSuccessfulRequest(): void
    {
        $sentryHub = $this->makeSentryHubMock();
        $sentryHub->expects($this->never())->method('captureException');

        $this->setupSuccessfulRequest();

        $this->makeWorker(sentryHub: $sentryHub)->start();
    }

    public function testSentryClientFlushedAfterEachRequest(): void
    {
        $sentryClient = $this->createMock(SentryClientInterface::class);
        $sentryClient->expects($this->once())->method('flush');

        $sentryHub = $this->makeSentryHubMock();
        $sentryHub->method('getClient')->willReturn($sentryClient);

        $this->setupSuccessfulRequest();

        $this->makeWorker(sentryHub: $sentryHub)->start();
    }

    public function testSentryFlushExceptionIsSwallowed(): void
    {
        $sentryClient = $this->createMock(SentryClientInterface::class);
        $sentryClient->method('flush')->willThrowException(new \RuntimeException('sentry down'));

        $sentryHub = $this->makeSentryHubMock();
        $sentryHub->method('getClient')->willReturn($sentryClient);

        $this->setupSuccessfulRequest();

        $this->makeWorker(sentryHub: $sentryHub)->start();
        $this->addToAssertionCount(1);
    }

    public function testNoExceptionWhenSentryHubIsNull(): void
    {
        $this->setupSuccessfulRequest();

        $this->makeWorker(sentryHub: null)->start();
        $this->addToAssertionCount(1);
    }
}
