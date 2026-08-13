<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerBootTest extends AbstractHttpWorkerTestCase
{
    public function testLazyBootSkipsKernelBoot(): void
    {
        $this->kernel->expects($this->never())->method('boot');
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);

        $this->makeWorker(lazyBoot: true)->start();
    }

    public function testEagerBootCallsKernelBoot(): void
    {
        $this->kernel->expects($this->once())->method('boot');
        $this->kernel->method('handle')->willReturn(new Response());
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);

        $this->makeWorker(lazyBoot: false)->start();
    }

    public function testBootIssuesNoRequest(): void
    {
        $this->kernel->expects($this->never())->method('handle');
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);

        $this->makeWorker(lazyBoot: false)->start();
    }

    public function testEarlyHintsSuppressedWhileBootWarmupFlagSet(): void
    {
        if (!\function_exists('headers_send')) {
            require_once __DIR__ . '/../../src/Resources/headers_send_polyfill.php';
        }
        HttpWorker::$currentHttpWorker = $this->spiralHttpWorker;

        // A warmer (via WorkerWarmupRunner) may run code that emits Early Hints, exactly
        // like an app's controller would (e.g. ViteEarlyHints::send -> sendHeaders(103)).
        // While the flag is set, no 103 frame may reach the worker — it would corrupt
        // the protocol.
        $this->spiralHttpWorker->expects($this->never())->method('respond');

        HttpWorker::$bootWarmupInProgress = true;
        try {
            $response = new Response();
            $response->headers->set('Link', '</style.css>; rel=preload');
            $response->sendHeaders(103);
        } finally {
            HttpWorker::$bootWarmupInProgress = false;
        }
    }

    protected function tearDown(): void
    {
        HttpWorker::$currentHttpWorker = null;
        HttpWorker::$bootWarmupInProgress = false;
        parent::tearDown();
    }

    public function testWorkerBootingEventAlwaysDispatched(): void
    {
        $this->spiralHttpWorker->method('waitRequest')->willReturn(null);

        $dispatched = [];
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatched) {
                $dispatched[] = $event;
                return $event;
            })
        ;

        $this->makeWorker()->start();

        $this->assertCount(
            1,
            array_filter($dispatched, static fn($e) => $e instanceof WorkerBootingEvent),
            'WorkerBootingEvent must be dispatched once before the loop',
        );
    }
}
