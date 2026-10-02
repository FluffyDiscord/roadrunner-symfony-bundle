<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Client\TemporalClientFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Temporal\Api\Workflowservice\V1\GetSystemInfoRequest;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\TimeoutException;

/**
 * TC-D1..D3 — the factory that builds the autowired Temporal client dependencies.
 */
class TemporalClientFactoryTest extends BaseTestCase
{
    public function testClientOptionsCarryNamespace(): void
    {
        self::assertSame('billing', TemporalClientFactory::clientOptions('billing')->namespace);
    }

    public function testClientOptionsFallBackToDefaultNamespaceWhenEmpty(): void
    {
        self::assertSame(ClientOptions::DEFAULT_NAMESPACE, TemporalClientFactory::clientOptions('')->namespace);
    }

    public function testEmptyAddressIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('address must not be empty');

        TemporalClientFactory::serviceClient('');
    }

    public function testContextWithoutTimeoutOnlyLimitsAttempts(): void
    {
        $context = TemporalClientFactory::createContext(null, 3);

        self::assertNull($context->getDeadline());
        self::assertSame(3, $context->getRetryOptions()->maximumAttempts);
    }

    public function testContextLimitsEachAttemptAndTheNumberOfAttempts(): void
    {
        $before = microtime(true);
        $context = TemporalClientFactory::createContext(2.5, 4);
        $deadline = $context->getDeadline();

        self::assertNotNull($deadline);
        self::assertEqualsWithDelta($before + 2.5, (float) $deadline->format('U.u'), 0.5);
        self::assertSame(4, $context->getRetryOptions()->maximumAttempts);
    }

    public function testServiceClientIsBuiltWithTheLimitedContext(): void
    {
        $this->skipWithoutGrpc();

        $client = TemporalClientFactory::serviceClient('127.0.0.1:7233', 'an-api-key', 2.5, 4);

        self::assertSame(4, $client->getContext()->getRetryOptions()->maximumAttempts);
        self::assertNotNull($client->getContext()->getDeadline());
    }

    public function testDefaultServiceClientHasNoTimeoutAndThreeAttempts(): void
    {
        $this->skipWithoutGrpc();

        $client = TemporalClientFactory::serviceClient('127.0.0.1:7233');

        self::assertInstanceOf(ServiceClientInterface::class, $client);
        self::assertNull($client->getContext()->getDeadline());
        self::assertSame(3, $client->getContext()->getRetryOptions()->maximumAttempts);
    }

    public function testDefaultServiceClientFailsFastWhenThePortIsClosed(): void
    {
        $this->skipWithoutGrpc();

        $client = TemporalClientFactory::serviceClient('127.0.0.1:1');
        $startedAt = microtime(true);

        try {
            $client->GetSystemInfo(new GetSystemInfoRequest());
            self::fail('A call to a closed port must fail.');
        } catch (ServiceClientException) {
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        self::assertLessThan(5.0, $elapsedSeconds, 'Three refused attempts plus ~1.5 s backoff must not take longer.');
    }

    public function testUnreachableTemporalFailsWithinTheLimit(): void
    {
        $this->skipWithoutGrpc();

        $client = TemporalClientFactory::serviceClient('127.0.0.1:1', null, 1.0, 3);
        $startedAt = microtime(true);

        try {
            $client->GetSystemInfo(new GetSystemInfoRequest());
            self::fail('A call to a dead address must fail.');
        } catch (ServiceClientException|TimeoutException) {
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        self::assertLessThan(6.0, $elapsedSeconds, 'Three 1 s attempts plus ~1.5 s backoff must not take longer.');
    }

    private function skipWithoutGrpc(): void
    {
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('The grpc extension is required to build a Temporal ServiceClient.');
        }
    }
}
