<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job\Live;

use PHPUnit\Framework\Attributes\Group;
use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\Jobs\Jobs;

/**
 * Live end-to-end tests for the Jobs message bus (docs/specs/jobs-message-bus.md).
 *
 * An HTTP request dispatches an #[AsJob] message; the jobs pool consumes the enveloped task, the
 * JobRoutingListener rehydrates it through the Native serializer and dispatches it into Symfony
 * Messenger, and the #[AsMessageHandler] runs with a real object carrying the exact token (observable
 * via /app/var/handled.json). A separate raw, non-enveloped task pushed straight via the RR Jobs API
 * still reaches a plain JobsRunEvent listener — proving the bus is purely additive.
 */
#[Group('jobs-live')]
class JobBusLiveTest extends AbstractJobsLiveTestCase
{
    public function testDispatchedMessageIsRoutedToHandler(): void
    {
        $token = 'bus-' . getmypid() . '-' . uniqid();
        $proof = self::varDir() . '/handled.json';
        @unlink($proof);

        self::assertSame(200, self::httpGet('/dispatch?token=' . $token), 'dispatch endpoint did not return 200');

        $handled = self::pollJson($proof);
        self::assertNotNull($handled, 'the #[AsMessageHandler] never wrote its proof');
        self::assertSame($token, $handled['token'] ?? null, 'handler received the wrong token');
        self::assertSame('App\\Ping', $handled['class'] ?? null, 'handler did not receive a rehydrated App\\Ping');
    }

    public function testRawNonEnvelopedTaskReachesPlainListener(): void
    {
        $proof = self::varDir() . '/raw-handled.json';
        @unlink($proof);

        $queue = (new Jobs(RPC::create(self::rrRpc())))->connect('default');
        $queue->dispatch($queue->create('app.raw_ping', 'raw-payload-XYZ'));

        $raw = self::pollJson($proof);
        self::assertNotNull($raw, 'the raw JobsRunEvent listener never ran');
        self::assertSame('raw-payload-XYZ', $raw['payload'] ?? null, 'raw task payload mismatch');
    }
}
