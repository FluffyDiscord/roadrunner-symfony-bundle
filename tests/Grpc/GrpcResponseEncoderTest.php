<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc;

use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcResponseEncoder;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Google\Rpc\Status;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;

/** TC-09 */
class GrpcResponseEncoderTest extends BaseTestCase
{
    private GrpcResponseEncoder $encoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->encoder = new GrpcResponseEncoder();
    }

    public function testEmptyHeadersEncodeAsEmptyJsonObject(): void
    {
        $encoded = $this->encoder->encodeSuccessHeaders(new ResponseHeaders(), new ResponseTrailers());

        self::assertSame('{}', $encoded);
    }

    public function testHeadersAndTrailersAreEmbeddedAsJsonStrings(): void
    {
        $headers = new ResponseHeaders(['x-echo' => '1']);
        $trailers = new ResponseTrailers(['x-sum' => 'abc']);

        $encoded = $this->encoder->encodeSuccessHeaders($headers, $trailers);

        $document = json_decode($encoded, true);
        self::assertIsArray($document);
        self::assertSame(['x-echo' => '1'], json_decode((string) $document['headers'], true), 'RoadRunner unmarshals each field into a Go string, so the value is nested JSON (spiral packHeaders semantics, verified live)');
        self::assertSame(['x-sum' => 'abc'], json_decode((string) $document['trailers'], true));
    }

    public function testErrorCarriesBase64GoogleRpcStatus(): void
    {
        $exception = GRPCException::create('boom', StatusCode::INVALID_ARGUMENT);

        $encoded = $this->encoder->encodeError($exception, 'boom', false, new ResponseHeaders(['x-echo' => '1']), new ResponseTrailers());
        $document = json_decode($encoded, true);

        self::assertIsArray($document);
        self::assertSame(['x-echo' => '1'], json_decode((string) $document['headers'], true));
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::INVALID_ARGUMENT, $status->getCode());
        self::assertSame('boom', $status->getMessage());
    }

    public function testAMaskedErrorDropsTheDetails(): void
    {
        $detail = new Status(['code' => StatusCode::INTERNAL, 'message' => 'App\Handler::Greet() got null']);
        $exception = GRPCException::create('App\Handler::Greet() must return Reply, got null', StatusCode::INTERNAL, null, [$detail]);

        $encoded = $this->encoder->encodeError($exception, 'Internal server error', true, new ResponseHeaders(), new ResponseTrailers());
        $document = json_decode($encoded, true);

        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::INTERNAL, $status->getCode());
        self::assertSame('Internal server error', $status->getMessage());
        self::assertCount(0, $status->getDetails());
    }

    public function testAnUnmaskedErrorKeepsTheDetails(): void
    {
        $detail = new Status(['code' => StatusCode::INVALID_ARGUMENT, 'message' => 'name is required']);
        $exception = GRPCException::create('boom', StatusCode::INVALID_ARGUMENT, null, [$detail]);

        $encoded = $this->encoder->encodeError($exception, 'boom', false, new ResponseHeaders(), new ResponseTrailers());
        $document = json_decode($encoded, true);

        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertCount(1, $status->getDetails());
    }

    public function testEncodeStatusBuildsUnavailableError(): void
    {
        $document = json_decode($this->encoder->encodeStatus(StatusCode::UNAVAILABLE, 'Worker boot failed'), true);

        self::assertIsArray($document);
        $status = new Status();
        $status->mergeFromString(base64_decode((string) $document['error']));
        self::assertSame(StatusCode::UNAVAILABLE, $status->getCode());
        self::assertSame('Worker boot failed', $status->getMessage());
    }
}
