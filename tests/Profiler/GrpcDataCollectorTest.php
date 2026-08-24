<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Profiler;

use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcDataCollector;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** TC-21 */
class GrpcDataCollectorTest extends BaseTestCase
{
    public function testStatusNamesResolveFromStatusCodeConstants(): void
    {
        self::assertSame('INVALID_ARGUMENT', GrpcDataCollector::statusName(StatusCode::INVALID_ARGUMENT));
        self::assertSame('OK', GrpcDataCollector::statusName(StatusCode::OK));
        self::assertSame('UNKNOWN(99)', GrpcDataCollector::statusName(99));
    }

    public function testCollectNeverClobbersPopulatedData(): void
    {
        $collector = new GrpcDataCollector();
        $collector->populateCall('bundle.test.Echo', 'Ping', 'App\\Echo', '{"a":1}', [], null);
        $collector->populateOutcome(false, StatusCode::INVALID_ARGUMENT, null, 'boom', 1.2, 123);

        $collector->collect(new Request(), new Response());

        self::assertTrue($collector->hasData());
        self::assertSame('bundle.test.Echo', $collector->getServiceName());
        self::assertSame('INVALID_ARGUMENT', $collector->getWorkerStatusName());
        self::assertSame(StatusCode::INVALID_ARGUMENT, $collector->getWorkerStatusCode());
        self::assertFalse($collector->isSuccess());
        self::assertSame('boom', $collector->getError());
    }
}
