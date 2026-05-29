<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerEarlyHintsTest extends AbstractHttpWorkerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('headers_send')) {
            require_once __DIR__ . '/../../src/Resources/headers_send_polyfill.php';
        }
    }

    protected function tearDown(): void
    {
        HttpWorker::$currentHttpWorker = null;
        parent::tearDown();
    }

    public function testCurrentHttpWorkerSetOnStart(): void
    {
        $this->psr7Worker->method('waitRequest')->willReturn(null);

        $this->makeWorker()->start();

        $this->assertSame($this->spiralHttpWorker, HttpWorker::$currentHttpWorker);
    }

    public function testEarlyHintsSentThroughRoadRunner(): void
    {
        HttpWorker::$currentHttpWorker = $this->spiralHttpWorker;

        $response = new Response();
        $response->headers->set('Link', '</style.css>; rel=preload');

        $this->spiralHttpWorker->expects($this->once())
            ->method('respond')
            ->with(
                103,
                '',
                $this->callback(static function (array $headers): bool {
                    return isset($headers['Link']) && $headers['Link'] === ['</style.css>; rel=preload'];
                }),
                false,
            );

        $response->sendHeaders(103);
    }

    public function testMultipleEarlyHintLinkHeaders(): void
    {
        HttpWorker::$currentHttpWorker = $this->spiralHttpWorker;

        $response = new Response();
        $response->headers->set('Link', '</style.css>; rel=preload', false);
        $response->headers->set('Link', '</app.js>; rel=preload', false);

        $this->spiralHttpWorker->expects($this->once())
            ->method('respond')
            ->with(
                103,
                '',
                $this->callback(static function (array $headers): bool {
                    return isset($headers['Link'])
                        && \in_array('</style.css>; rel=preload', $headers['Link'], true)
                        && \in_array('</app.js>; rel=preload', $headers['Link'], true);
                }),
                false,
            );

        $response->sendHeaders(103);
    }

    public function testEarlyHintsFollowedByFinalResponse(): void
    {
        $respondCalls = [];
        $this->spiralHttpWorker->method('respond')
            ->willReturnCallback(function () use (&$respondCalls): void {
                $respondCalls[] = func_get_args();
            });

        $response = new Response('ok', 200);
        $response->headers->set('Link', '</style.css>; rel=preload');

        $this->kernel->method('handle')->willReturnCallback(function () use ($response): Response {
            $response->sendHeaders(103);
            return $response;
        });

        $this->psr7Worker->method('waitRequest')->willReturnOnConsecutiveCalls($this->psrRequest(), null);
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->makeWorker()->start();

        $this->assertCount(2, $respondCalls);

        $this->assertSame(103, $respondCalls[0][0]);
        $this->assertSame('', $respondCalls[0][1]);
        $this->assertFalse($respondCalls[0][3]);
        $this->assertContains('</style.css>; rel=preload', $respondCalls[0][2]['Link']);

        $this->assertSame(200, $respondCalls[1][0]);
    }

    public function testExceptionAfterEarlyHintsSendsErrorResponse(): void
    {
        $spiralRespondCalls = [];
        $this->spiralHttpWorker->method('respond')
            ->willReturnCallback(function () use (&$spiralRespondCalls): void {
                $spiralRespondCalls[] = func_get_args();
            });

        $psr7RespondCalls = [];
        $this->psr7Worker->method('respond')
            ->willReturnCallback(function () use (&$psr7RespondCalls): void {
                $psr7RespondCalls[] = func_get_args();
            });

        $this->psr7Worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), null);
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->kernel->method('handle')->willReturnCallback(function (): never {
            $response = new Response();
            $response->headers->set('Link', '</style.css>; rel=preload');
            $response->sendHeaders(103);
            throw new \RuntimeException('boom after early hints');
        });

        $this->makeWorker(debug: false)->start();

        // 103 early hints were sent before the exception
        $this->assertCount(1, $spiralRespondCalls);
        $this->assertSame(103, $spiralRespondCalls[0][0]);
        $this->assertFalse($spiralRespondCalls[0][3]);

        // 500 error response sent after the exception
        $this->assertCount(1, $psr7RespondCalls);
        $this->assertSame(500, $psr7RespondCalls[0][0]->getStatusCode());
    }

    public function testNextRequestCleanAfterExceptionWithEarlyHints(): void
    {
        $spiralRespondCalls = [];
        $this->spiralHttpWorker->method('respond')
            ->willReturnCallback(function () use (&$spiralRespondCalls): void {
                $spiralRespondCalls[] = func_get_args();
            });

        $callCount = 0;
        $this->kernel->method('handle')->willReturnCallback(function () use (&$callCount): Response {
            $callCount++;
            if ($callCount === 1) {
                $response = new Response();
                $response->headers->set('Link', '</style.css>; rel=preload');
                $response->sendHeaders(103);
                throw new \RuntimeException('boom');
            }
            return new Response('ok', 200);
        });

        $this->psr7Worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($this->psrRequest(), $this->psrRequest(), null);
        $this->httpFoundationFactory->method('createRequest')->willReturn(new Request());

        $this->makeWorker(debug: false)->start();

        // Last respond call is the second request's 200 — no stale Link header
        $finalResponse = end($spiralRespondCalls);
        $this->assertSame(200, $finalResponse[0]);
        $this->assertArrayNotHasKey('link', $finalResponse[2]);
    }

    /**
     * A 103 early-hint is informational and does NOT set responseStarted. If the worker then dies
     * (die/exit/fatal) before the final response starts, the shutdown rescue still validly sends a
     * 500 — the 103+500 sequence is the same one the catch path uses. (Contrast: a streamed FINAL
     * response in progress sets responseStarted and IS suppressed — see HttpWorkerShutdownTest.)
     */
    public function testFatalAfterEarlyHintsStillSendsRescueResponse(): void
    {
        $respondCalls = [];
        $this->spiralHttpWorker->method('respond')
            ->willReturnCallback(function () use (&$respondCalls): void {
                $respondCalls[] = func_get_args();
            });

        HttpWorker::$currentHttpWorker = $this->spiralHttpWorker;
        $response = new Response();
        $response->headers->set('Link', '</style.css>; rel=preload');
        $response->sendHeaders(103); // the 103 frame goes out

        // worker dies after the early hint but before the final response (responseStarted === false)
        $worker = $this->makeWorker(debug: true);
        $worker->callHandleShutdown($this->psr7Worker, true, false, ['message' => 'died after hints', 'file' => 'f', 'line' => 1]);

        $this->assertCount(2, $respondCalls);
        $this->assertSame(103, $respondCalls[0][0]); // informational early hint
        $this->assertSame(500, $respondCalls[1][0]); // rescue final response after the fatal
    }

    public function testHeadersSendNoOpWithoutCurrentWorker(): void
    {
        HttpWorker::$currentHttpWorker = null;

        $response = new Response();
        $response->headers->set('Link', '</style.css>; rel=preload');

        $this->spiralHttpWorker->expects($this->never())->method('respond');

        $response->sendHeaders(103);
    }

    public function testHeadersSendNoOpForNonInformationalStatus(): void
    {
        HttpWorker::$currentHttpWorker = $this->spiralHttpWorker;

        $this->spiralHttpWorker->expects($this->never())->method('respond');

        $result = headers_send(200);

        $this->assertSame(200, $result);
    }

    public function testHeadersSendNoOpWhenCalledOutsideResponse(): void
    {
        HttpWorker::$currentHttpWorker = $this->spiralHttpWorker;

        $this->spiralHttpWorker->expects($this->never())->method('respond');

        $result = headers_send(103);

        $this->assertSame(103, $result);
    }
}
