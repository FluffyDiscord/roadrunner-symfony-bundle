<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Spiral\RoadRunner\Environment\Mode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerResponseRoutingTest extends AbstractHttpWorkerTestCase
{
    public function testDefaultResponsePassedToHttpWorkerRespond(): void
    {
        $this->setupSuccessfulRequest(new Response('hello', 200, ['X-Custom' => 'test']));

        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(200, new \PHPUnit\Framework\Constraint\IsType(\PHPUnit\Framework\NativeType::String), $this->arrayHasKey('x-custom'))
        ;

        $this->makeWorker()->start();
    }

    public function testStreamedResponseYieldsGenerator(): void
    {
        $this->setupSuccessfulRequest(
            new StreamedResponse(fn() => print('chunk'), 200),
        );

        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(200, $this->isInstanceOf(\Generator::class), $this->anything())
        ;

        $this->makeWorker()->start();
    }

    public function testStreamedJsonResponseYieldsGenerator(): void
    {
        $this->setupSuccessfulRequest(
            new StreamedJsonResponse(['key' => 'val'], 200),
        );

        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(200, $this->isInstanceOf(\Generator::class), $this->anything())
        ;

        $this->makeWorker()->start();
    }

    public function testBinaryFileResponsePassedToHttpWorkerRespond(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rr_');
        file_put_contents($tmp, 'binary');

        $this->setupSuccessfulRequest(new BinaryFileResponse($tmp, 200));

        $this->spiralHttpWorker
            ->expects($this->once())
            ->method('respond')
            ->with(200, $this->anything(), $this->anything())
        ;

        $this->makeWorker()->start();

        unlink($tmp);
    }

    public function testWorkerRequestReceivedEventDispatched(): void
    {
        $this->setupSuccessfulRequest();

        $dispatched = [];
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatched) {
                $dispatched[] = $e;
                return $e;
            })
        ;

        $this->makeWorker()->start();

        $this->assertCount(
            1,
            array_filter($dispatched, static fn($e) => $e instanceof WorkerRequestReceivedEvent),
        );
    }

    public function testWorkerResponseSentEventDispatchedWithHttpMode(): void
    {
        $this->setupSuccessfulRequest();

        $dispatched = [];
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatched) {
                $dispatched[] = $e;
                return $e;
            })
        ;

        $this->makeWorker()->start();

        $responseSentEvents = array_values(array_filter(
            $dispatched,
            static fn($e) => $e instanceof WorkerResponseSentEvent,
        ));

        $this->assertCount(1, $responseSentEvents);
        $this->assertSame(Mode::MODE_HTTP, $responseSentEvents[0]->workerType);
    }
}
