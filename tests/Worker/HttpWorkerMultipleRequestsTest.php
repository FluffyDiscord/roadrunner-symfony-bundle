<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
class HttpWorkerMultipleRequestsTest extends AbstractHttpWorkerTestCase
{
    public function testMultipleRequestsProcessedSequentially(): void
    {
        $callCount = 0;
        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnCallback(function () use (&$callCount) {
                return ++$callCount <= 3 ? $this->rrRequest() : null;
            })
        ;

        $this->kernel->method('handle')->willReturn(new Response('ok'));

        $this->spiralHttpWorker->expects($this->exactly(3))->method('respond');

        $this->makeWorker()->start();
    }

    public function testServicesResetterCalledOncePerRequestInProdMode(): void
    {
        $callCount = 0;
        $this->spiralHttpWorker
            ->method('waitRequest')
            ->willReturnCallback(function () use (&$callCount) {
                return ++$callCount <= 2 ? $this->rrRequest() : null;
            })
        ;

        $this->kernel->method('handle')->willReturn(new Response());

        $this->servicesResetter->expects($this->exactly(2))->method('reset');

        $this->makeWorker(debug: false)->start();
    }
}
