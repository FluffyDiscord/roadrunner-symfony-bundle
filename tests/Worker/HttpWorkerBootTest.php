<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerBootTest extends AbstractHttpWorkerTestCase
{
    public function testLazyBootSkipsKernelBoot(): void
    {
        $this->kernel->expects($this->never())->method('boot');
        $this->psr7Worker->method('waitRequest')->willReturn(null);

        $this->makeWorker(lazyBoot: true)->start();
    }

    public function testEagerBootCallsKernelBoot(): void
    {
        $this->kernel->expects($this->once())->method('boot');
        $this->kernel->method('handle')->willReturn(new Response());
        $this->psr7Worker->method('waitRequest')->willReturn(null);

        $this->makeWorker(lazyBoot: false)->start();
    }

    public function testEarlyRouterInitializationIssuesDummyRequest(): void
    {
        $this->kernel->expects($this->once())->method('boot');

        $dummyHandled = false;
        $this->kernel
            ->method('handle')
            ->willReturnCallback(function (Request $request) use (&$dummyHandled) {
                if ($request->attributes->get(HttpWorker::DUMMY_REQUEST_ATTRIBUTE)) {
                    $dummyHandled = true;
                }
                return new Response();
            })
        ;

        $this->psr7Worker->method('waitRequest')->willReturn(null);

        $this->makeWorker(earlyRouterInit: true, lazyBoot: false)->start();

        $this->assertTrue($dummyHandled, 'Dummy request with DUMMY_REQUEST_ATTRIBUTE must be dispatched to kernel');
    }

    public function testEarlyRouterInitSkippedWhenDisabled(): void
    {
        $this->kernel->expects($this->never())->method('handle');
        $this->psr7Worker->method('waitRequest')->willReturn(null);

        $this->makeWorker(earlyRouterInit: false, lazyBoot: false)->start();
    }

    public function testWorkerBootingEventAlwaysDispatched(): void
    {
        $this->psr7Worker->method('waitRequest')->willReturn(null);

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
