<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Client\TemporalClientFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClientInterface;

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

    public function testServiceClientIsBuilt(): void
    {
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('The grpc extension is required to build a Temporal ServiceClient.');
        }

        self::assertInstanceOf(ServiceClientInterface::class, TemporalClientFactory::serviceClient('127.0.0.1:7233'));
        self::assertInstanceOf(ServiceClientInterface::class, TemporalClientFactory::serviceClient('127.0.0.1:7233', 'an-api-key'));
    }
}
