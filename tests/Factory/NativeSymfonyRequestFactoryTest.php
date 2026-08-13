<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Factory;

use FluffyDiscord\RoadRunnerBundle\Factory\NativeSymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\RoadRunner\Http\GlobalState;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class NativeSymfonyRequestFactoryTest extends BaseTestCase
{
    private NativeSymfonyRequestFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new NativeSymfonyRequestFactory();
    }

    public function testBridgeServerVariableBlockIsPresent(): void
    {
        $rrRequest = $this->rrRequest(uri: 'http://localhost:8080/a/b?x=1&z=abc');

        $request = $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('localhost', $request->server->get('SERVER_NAME'));
        $this->assertSame(8080, $request->server->get('SERVER_PORT'));
        $this->assertSame('/a/b?x=1&z=abc', $request->server->get('REQUEST_URI'));
        $this->assertSame('x=1&z=abc', $request->server->get('QUERY_STRING'));
        $this->assertSame('GET', $request->server->get('REQUEST_METHOD'));
        $this->assertFalse($request->server->has('HTTPS'));
        $this->assertSame(['x' => '1', 'z' => 'abc'], $request->query->all());
    }

    public function testHttpsAndMixedCaseUri(): void
    {
        $rrRequest = $this->rrRequest(uri: 'HTTPS://Secure.EXAMPLE.com:8443/path');

        $request = $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));

        $this->assertSame('on', $request->server->get('HTTPS'));
        $this->assertSame('secure.example.com', $request->server->get('SERVER_NAME'));
        $this->assertSame(8443, $request->server->get('SERVER_PORT'));
        $this->assertTrue($request->isSecure());
    }

    public function testHttpsWithoutPortDefaultsTo443(): void
    {
        $rrRequest = $this->rrRequest(uri: 'https://secure.example.com/path');

        $request = $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));

        $this->assertSame(443, $request->server->get('SERVER_PORT'));
    }

    public function testUnparseableUriThrows(): void
    {
        $rrRequest = $this->rrRequest(uri: 'http:///nothing');

        $this->expectException(\InvalidArgumentException::class);

        $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));
    }

    public function testParsedJsonBodyFillsRequestBag(): void
    {
        $rrRequest = $this->rrRequest(body: '{"a":1}', parsed: true);

        $request = $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));

        $this->assertSame(['a' => 1], $request->request->all());
        $this->assertTrue($request->attributes->get(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME));
        $this->assertSame('{"a":1}', $request->getContent());
    }

    public function testInvalidParsedJsonThrows(): void
    {
        $rrRequest = $this->rrRequest(body: '{invalid', parsed: true);

        $this->expectException(\JsonException::class);

        $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));
    }

    public function testNestedUploadsKeepStructureAndMoveWorksInTestMode(): void
    {
        $uploadPath = (string) tempnam(sys_get_temp_dir(), 'native');
        file_put_contents($uploadPath, 'hello');

        $rrRequest = $this->rrRequest(uploads: [
            'batch' => [
                ['name' => 'ok.txt', 'error' => \UPLOAD_ERR_OK, 'tmpName' => $uploadPath, 'size' => 5, 'mime' => 'text/plain'],
                ['name' => 'gone.txt', 'error' => \UPLOAD_ERR_NO_FILE, 'tmpName' => '', 'size' => 0, 'mime' => ''],
            ],
        ]);

        $request = $this->factory->createRequest($rrRequest, GlobalState::enrichServerVars($rrRequest));

        $batch = $request->files->get('batch');
        $this->assertIsArray($batch);

        $okFile = $batch[0];
        $this->assertInstanceOf(UploadedFile::class, $okFile);
        $this->assertSame('ok.txt', $okFile->getClientOriginalName());
        $this->assertSame($uploadPath, $okFile->getPathname());

        $goneFile = $batch[1];
        $this->assertInstanceOf(UploadedFile::class, $goneFile);
        $this->assertSame(\UPLOAD_ERR_NO_FILE, $goneFile->getError());
        $this->assertFalse($goneFile->isValid());

        $moveTargetDirectory = sys_get_temp_dir() . '/native-factory-move-' . uniqid('', true);
        $movedFile = $okFile->move($moveTargetDirectory, 'moved.txt');
        $this->assertSame('hello', file_get_contents($movedFile->getPathname()));

        @unlink($movedFile->getPathname());
        @rmdir($moveTargetDirectory);
    }

    /**
     * @param array<array-key, mixed> $uploads
     */
    private function rrRequest(
        string $uri = 'http://localhost/test',
        array  $uploads = [],
        string $body = '',
        bool   $parsed = false,
    ): RoadRunnerRequest
    {
        parse_str(parse_url($uri, \PHP_URL_QUERY) ?: '', $queryParameters);

        return new RoadRunnerRequest(
            method: 'GET',
            uri: $uri,
            headers: ['Host' => ['localhost']],
            attributes: [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => $parsed],
            uploads: $uploads,
            query: $queryParameters,
            body: $body,
            parsed: $parsed,
        );
    }
}
