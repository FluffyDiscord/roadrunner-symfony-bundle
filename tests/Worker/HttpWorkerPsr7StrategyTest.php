<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerPsr7StrategyTest extends AbstractHttpWorkerTestCase
{
    public function testLoopRespondsThroughPsr7Strategy(): void
    {
        $this->spiralHttpWorker->method('waitRequest')->willReturnOnConsecutiveCalls($this->rrRequest(), null);
        $this->kernel->method('handle')->willReturn(new Response('psr7-ok', 200));

        $respondedContent = null;
        $this->spiralHttpWorker
            ->method('respond')
            ->willReturnCallback(function (int $status, mixed $content) use (&$respondedContent): void {
                $respondedContent = $content;
            })
        ;

        $worker = $this->makeWorker(symfonyRequestFactory: new Psr7SymfonyRequestFactory());
        $worker->start();

        $this->assertSame('psr7-ok', $respondedContent);
    }

    public function testCustomHttpFoundationFactoryDecoratesConversionInsideLoop(): void
    {
        $this->spiralHttpWorker->method('waitRequest')->willReturnOnConsecutiveCalls($this->rrRequest(), null);

        $decoratedRequest = new Request(attributes: ['decorated' => true]);
        $customFactory = new class($decoratedRequest) implements HttpFoundationFactoryInterface {
            public ?ServerRequestInterface $receivedPsrRequest = null;

            public function __construct(private readonly Request $decoratedRequest)
            {
            }

            public function createRequest(ServerRequestInterface $psrRequest, bool $streamed = false): Request
            {
                $this->receivedPsrRequest = $psrRequest;

                return $this->decoratedRequest;
            }

            public function createResponse(\Psr\Http\Message\ResponseInterface $psrResponse, bool $streamed = false): Response
            {
                throw new \LogicException('not used');
            }
        };

        $handledRequest = null;
        $this->kernel
            ->method('handle')
            ->willReturnCallback(function (Request $request) use (&$handledRequest): Response {
                $handledRequest = $request;

                return new Response('ok');
            })
        ;

        $worker = $this->makeWorker(symfonyRequestFactory: new Psr7SymfonyRequestFactory($customFactory));
        $worker->start();

        $this->assertSame($decoratedRequest, $handledRequest);
        $this->assertInstanceOf(ServerRequestInterface::class, $customFactory->receivedPsrRequest);
        $this->assertSame('GET', $customFactory->receivedPsrRequest->getMethod());
    }

    public function testConstructorFallbackUsesLegacyFactoryParameter(): void
    {
        $this->spiralHttpWorker->method('waitRequest')->willReturnOnConsecutiveCalls($this->rrRequest(), null);

        $customFactory = new class implements HttpFoundationFactoryInterface {
            public int $createRequestCalls = 0;

            public function createRequest(ServerRequestInterface $psrRequest, bool $streamed = false): Request
            {
                ++$this->createRequestCalls;

                return new Request();
            }

            public function createResponse(\Psr\Http\Message\ResponseInterface $psrResponse, bool $streamed = false): Response
            {
                throw new \LogicException('not used');
            }
        };

        $this->kernel->method('handle')->willReturn(new Response('ok'));

        $worker = new TestableHttpWorker(
            lazyBoot: true,
            kernel: $this->kernel,
            eventDispatcher: $this->eventDispatcher,
            debug: false,
            servicesResetter: $this->servicesResetter,
            httpFoundationFactory: $customFactory,
        );
        $worker->injectPsr7Worker($this->psr7Worker);
        $worker->start();

        $this->assertSame(1, $customFactory->createRequestCalls);
    }
}
