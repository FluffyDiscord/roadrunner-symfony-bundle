<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Worker;

use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\InvalidEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Request;
use RoadRunner\Centrifugo\Request\RequestFactory;
use Spiral\Goridge\Exception\HeaderException;
use Spiral\Goridge\Frame;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadRunner\Worker;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class WaitCountingCentrifugoWorker extends CentrifugoWorker
{
    public int $waitCount = 0;

    protected function waitRequest(): ?Request\RequestInterface
    {
        $this->waitCount++;

        if ($this->waitCount > 3) {
            return null;
        }

        return parent::waitRequest();
    }

    protected function logError(string $message): void
    {
    }

    protected function registerShutdown(callable $handler): void
    {
    }
}

#[AllowMockObjectsWithoutExpectations]
class CentrifugoWorkerRelayFailureTest extends BaseTestCase
{
    /** @var list<object> */
    private array $dispatchedEvents = [];

    public function testDeadRelayStopsTheLoopInsteadOfSpinningOnInvalidRequests(): void
    {
        $worker = $this->makeWorker('');

        try {
            $worker->start();
            self::fail('The relay failure must end the worker loop.');
        } catch (HeaderException) {
        }

        self::assertSame(1, $worker->waitCount);
        self::assertSame([], $this->getDispatchedInvalidEvents());
    }

    public function testMalformedPayloadIsStillDispatchedAsInvalidEvent(): void
    {
        $malformedHeader = '{not json';
        $malformedFrame = Frame::packFrame(new Frame($malformedHeader . 'body', [\strlen($malformedHeader)]));
        $worker = $this->makeWorker($malformedFrame);

        try {
            $worker->start();
            self::fail('The relay failure after the malformed payload must end the worker loop.');
        } catch (HeaderException) {
        }

        self::assertSame(2, $worker->waitCount);
        self::assertCount(1, $this->getDispatchedInvalidEvents());
    }

    private function makeWorker(string $relayInput): WaitCountingCentrifugoWorker
    {
        $input = fopen('php://memory', 'w+');
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($input);
        self::assertIsResource($output);

        fwrite($input, $relayInput);
        rewind($input);

        $goridgeWorker = new Worker(new StreamRelay($input, $output), interceptSideEffects: false);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->dispatchedEvents[] = $event;

            return $event;
        });

        return new WaitCountingCentrifugoWorker(
            lazyBoot: true,
            debug: false,
            kernel: $this->createMock(KernelInterface::class),
            worker: new RoadRunnerCentrifugoWorker($goridgeWorker, new RequestFactory($goridgeWorker)),
            eventDispatcher: $eventDispatcher,
            servicesResetter: null,
        );
    }

    /**
     * @return list<object>
     */
    private function getDispatchedInvalidEvents(): array
    {
        return array_values(array_filter($this->dispatchedEvents, static fn(object $event): bool => $event instanceof InvalidEvent));
    }
}
