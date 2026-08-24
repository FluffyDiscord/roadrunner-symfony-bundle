<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcFrameDecodingException;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcFrameDecoder;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;

/** TC-08 */
class GrpcFrameDecoderTest extends BaseTestCase
{
    private GrpcFrameDecoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new GrpcFrameDecoder();
    }

    public function testDecodesValidFrameHeader(): void
    {
        $header = '{"service":"bundle.test.Echo","method":"Ping","context":{"x-test":["1"],"authorization":["Bearer x"]}}';

        $frame = $this->decoder->decode($header);

        self::assertSame('bundle.test.Echo', $frame->serviceName);
        self::assertSame('Ping', $frame->methodName);
        self::assertSame(['x-test' => ['1'], 'authorization' => ['Bearer x']], $frame->metadata);
    }

    public function testDecodesMissingContextAsEmptyMetadata(): void
    {
        $frame = $this->decoder->decode('{"service":"s","method":"m"}');

        self::assertSame([], $frame->metadata);
    }

    public function testDecodesScalarMetadataValueAsSingleElementList(): void
    {
        $frame = $this->decoder->decode('{"service":"s","method":"m","context":{"k":"v"}}');

        self::assertSame(['k' => ['v']], $frame->metadata);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(GrpcFrameDecodingException::class);

        $this->decoder->decode('not json');
    }

    public function testRejectsMissingServiceKey(): void
    {
        $this->expectException(GrpcFrameDecodingException::class);

        $this->decoder->decode('{"method":"m"}');
    }

    public function testRejectsNonStringService(): void
    {
        $this->expectException(GrpcFrameDecodingException::class);

        $this->decoder->decode('{"service":1,"method":"m"}');
    }

    public function testRejectsNonListMetadataValue(): void
    {
        $this->expectException(GrpcFrameDecodingException::class);

        $this->decoder->decode('{"service":"s","method":"m","context":{"k":{"nested":1}}}');
    }
}
