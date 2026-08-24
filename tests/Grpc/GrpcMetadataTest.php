<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\RoadRunner\GRPC\Context;

/** TC-30 */
class GrpcMetadataTest extends BaseTestCase
{
    public function testKeysAreLowerCasedAndCollisionsMerge(): void
    {
        $metadata = new GrpcMetadata(['Authorization' => ['Bearer x'], 'authorization' => ['Bearer y'], 'x-a' => ['1', '2']]);

        self::assertSame(['Bearer x', 'Bearer y'], $metadata->getAll('authorization'));
        self::assertSame('Bearer x', $metadata->getFirst('AUTHORIZATION'));
        self::assertSame(['1', '2'], $metadata->getAll('x-a'));
        self::assertSame(['authorization', 'x-a'], $metadata->getKeys());
    }

    public function testBearerTokenIsExtractedCaseInsensitively(): void
    {
        $metadata = new GrpcMetadata(['authorization' => ['bearer secret-token']]);

        self::assertSame('secret-token', $metadata->getBearerToken());
    }

    public function testBearerTokenIsNullWithoutThePrefix(): void
    {
        $metadata = new GrpcMetadata(['authorization' => ['Basic abc']]);

        self::assertNull($metadata->getBearerToken());
    }

    public function testFromContextReadsTheBundleEntry(): void
    {
        $metadata = new GrpcMetadata(['x-a' => ['1']]);
        $context = new Context([GrpcMetadata::class => $metadata]);

        self::assertSame($metadata, GrpcMetadata::fromContext($context));
    }

    public function testFromContextOutsideAServedCallThrows(): void
    {
        $this->expectException(\LogicException::class);

        GrpcMetadata::fromContext(new Context([]));
    }
}
