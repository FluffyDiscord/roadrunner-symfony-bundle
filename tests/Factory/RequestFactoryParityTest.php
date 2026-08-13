<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Factory;

use FluffyDiscord\RoadRunnerBundle\Factory\NativeSymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spiral\RoadRunner\Http\GlobalState;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\UploadedFile as BridgeUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class RequestFactoryParityTest extends BaseTestCase
{
    /** @var list<string> */
    private static array $temporaryFiles = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }
        self::$temporaryFiles = [];

        parent::tearDownAfterClass();
    }

    /**
     * @return array<string, array{0: RoadRunnerRequest, 1: list<string>}>
     */
    public static function equalityFixtures(): array
    {
        $uploadPath = self::createUploadFixtureFile('upload-content');
        $nestedPathA = self::createUploadFixtureFile('nested-a');
        $nestedPathB = self::createUploadFixtureFile('nested-b');

        return [
            'plain http GET' => [self::rrRequest(), []],
            'https uri' => [self::rrRequest(uri: 'https://secure.example.com/a/b?x=1'), []],
            'mixed-case scheme and host' => [self::rrRequest(uri: 'HTTPS://Secure.EXAMPLE.com/a?x=1'), []],
            'non-default port' => [self::rrRequest(uri: 'https://secure.example.com:8443/a'), []],
            'uri without host' => [self::rrRequest(uri: '/relative/path?x=1'), []],
            'explicit default port, no Host header' => [
                self::rrRequest(uri: 'https://host.example.com:443/path', headers: ['X-Other' => ['1']]),
                ['Host'],
            ],
            'empty query' => [self::rrRequest(uri: 'http://localhost/plain'), []],
            'multi-value headers' => [
                self::rrRequest(headers: [
                    'Host'            => ['localhost'],
                    'X-Multi'         => ['one', 'two'],
                    'Accept-Encoding' => ['gzip', 'br'],
                ]),
                [],
            ],
            'parsed json body' => [
                self::rrRequest(body: '{"alpha":1,"beta":{"nested":true}}', parsed: true, attributes: [
                    RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => true,
                    'custom-attribute'                            => 'value',
                ]),
                [],
            ],
            'unparsed form body' => [
                self::rrRequest(
                    method: 'POST',
                    headers: ['Host' => ['localhost'], 'Content-Type' => ['application/x-www-form-urlencoded']],
                    body: 'field=value&other=1',
                ),
                [],
            ],
            'single upload' => [
                self::rrRequest(uploads: [
                    'document' => ['name' => 'doc.txt', 'error' => \UPLOAD_ERR_OK, 'tmpName' => $uploadPath, 'size' => 14, 'mime' => 'text/plain'],
                ]),
                [],
            ],
            'nested uploads' => [
                self::rrRequest(uploads: [
                    'batch' => [
                        ['name' => 'a.txt', 'error' => \UPLOAD_ERR_OK, 'tmpName' => $nestedPathA, 'size' => 8, 'mime' => 'text/plain'],
                        ['name' => 'b.txt', 'error' => \UPLOAD_ERR_OK, 'tmpName' => $nestedPathB, 'size' => 8, 'mime' => 'text/plain'],
                    ],
                ]),
                [],
            ],
            'upload with error' => [
                self::rrRequest(uploads: [
                    'missing' => ['name' => 'gone.txt', 'error' => \UPLOAD_ERR_NO_FILE, 'tmpName' => '', 'size' => 0, 'mime' => ''],
                ]),
                [],
            ],
        ];
    }

    #[DataProvider('equalityFixtures')]
    public function testNativeMatchesPsr7Strategy(RoadRunnerRequest $rrRequest, array $excludedHeaders): void
    {
        $server = GlobalState::enrichServerVars($rrRequest);

        $nativeRequest = new NativeSymfonyRequestFactory()->createRequest($rrRequest, $server);
        $psr7Request = new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server);

        $this->assertRequestParity($psr7Request, $nativeRequest, $excludedHeaders);
    }

    #[DataProvider('equalityFixtures')]
    public function testPsr7StrategyMatchesLegacyChainOracle(RoadRunnerRequest $rrRequest, array $excludedHeaders): void
    {
        $server = GlobalState::enrichServerVars($rrRequest);

        $oracleRequest = $this->createRequestThroughLegacyChain($rrRequest, $server);
        $psr7Request = new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server);

        $this->assertSame($oracleRequest->getMethod(), $psr7Request->getMethod());
        $this->assertSame($oracleRequest->getUri(), $psr7Request->getUri());
        $this->assertSame($oracleRequest->query->all(), $psr7Request->query->all());
        $this->assertSame($oracleRequest->request->all(), $psr7Request->request->all());
        $this->assertSame($oracleRequest->attributes->all(), $psr7Request->attributes->all());
        $this->assertSame($oracleRequest->cookies->all(), $psr7Request->cookies->all());
        $this->assertSame($oracleRequest->headers->all(), $psr7Request->headers->all());
        $this->assertSame($oracleRequest->getContent(), $psr7Request->getContent());
        $this->assertSame($this->comparableServer($oracleRequest), $this->comparableServer($psr7Request));
        $this->assertSame($oracleRequest->server->get('REQUEST_URI'), $psr7Request->server->get('REQUEST_URI'));
        $this->assertSame($oracleRequest->server->get('QUERY_STRING'), $psr7Request->server->get('QUERY_STRING'));
        $this->assertSame($this->describeFiles($oracleRequest->files->all()), $this->describeFiles($psr7Request->files->all()));
    }

    public function testUnparseableUriThrowsInBothStrategies(): void
    {
        $rrRequest = self::rrRequest(uri: 'http:///nothing');
        $server = GlobalState::enrichServerVars($rrRequest);

        try {
            new NativeSymfonyRequestFactory()->createRequest($rrRequest, $server);
            $this->fail('native strategy should throw on unparseable uri');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server);
            $this->fail('psr7 strategy should throw on unparseable uri');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDeltaD1AndD2UploadClassAndPathnameProvenance(): void
    {
        $uploadPath = self::createUploadFixtureFile('delta-content');

        $rrRequest = self::rrRequest(uploads: [
            'file' => ['name' => 'delta.txt', 'error' => \UPLOAD_ERR_OK, 'tmpName' => $uploadPath, 'size' => 13, 'mime' => 'text/plain'],
        ]);
        $server = GlobalState::enrichServerVars($rrRequest);

        $nativeFile = new NativeSymfonyRequestFactory()->createRequest($rrRequest, $server)->files->get('file');
        $psr7File = new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server)->files->get('file');

        $this->assertInstanceOf(UploadedFile::class, $nativeFile);
        $this->assertNotInstanceOf(BridgeUploadedFile::class, $nativeFile);
        $this->assertInstanceOf(BridgeUploadedFile::class, $psr7File);

        $this->assertSame($uploadPath, $nativeFile->getPathname());
        $this->assertNotSame($uploadPath, $psr7File->getPathname());
        $this->assertSame('delta-content', file_get_contents($nativeFile->getPathname()));
    }

    public function testDeltaD3NoFileUploadPathnameProvenance(): void
    {
        $rrRequest = self::rrRequest(uploads: [
            'missing' => ['name' => 'gone.txt', 'error' => \UPLOAD_ERR_NO_FILE, 'tmpName' => '/tmp/rr-delivered-anyway', 'size' => 0, 'mime' => ''],
        ]);
        $server = GlobalState::enrichServerVars($rrRequest);

        $nativeFile = new NativeSymfonyRequestFactory()->createRequest($rrRequest, $server)->files->get('missing');
        $psr7File = new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server)->files->get('missing');

        $this->assertInstanceOf(UploadedFile::class, $nativeFile);
        $this->assertInstanceOf(UploadedFile::class, $psr7File);
        $this->assertSame('/tmp/rr-delivered-anyway', $nativeFile->getPathname());
        $this->assertSame('', $psr7File->getPathname());
        $this->assertSame(\UPLOAD_ERR_NO_FILE, $nativeFile->getError());
        $this->assertSame(\UPLOAD_ERR_NO_FILE, $psr7File->getError());
    }

    public function testDeltaD5QueryStringEncoding(): void
    {
        $rrRequest = self::rrRequest(uri: 'http://localhost/list?y[]=2&y[]=3');
        $server = GlobalState::enrichServerVars($rrRequest);

        $nativeRequest = new NativeSymfonyRequestFactory()->createRequest($rrRequest, $server);
        $psr7Request = new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server);

        $nativeQueryString = $nativeRequest->server->get('QUERY_STRING');
        $psr7QueryString = $psr7Request->server->get('QUERY_STRING');

        $this->assertIsString($nativeQueryString);
        $this->assertIsString($psr7QueryString);
        $this->assertSame(rawurldecode($psr7QueryString), rawurldecode($nativeQueryString));
        $this->assertSame($psr7Request->query->all(), $nativeRequest->query->all());
    }

    public function testDeltaD6HeaderValidationRelaxation(): void
    {
        $paddedRequest = self::rrRequest(headers: ['Host' => ['localhost'], 'X-Padded' => ['  padded  ']]);
        $server = GlobalState::enrichServerVars($paddedRequest);

        $nativeRequest = new NativeSymfonyRequestFactory()->createRequest($paddedRequest, $server);
        $psr7Request = new Psr7SymfonyRequestFactory()->createRequest($paddedRequest, $server);

        $this->assertSame('  padded  ', $nativeRequest->headers->get('X-Padded'));
        $this->assertSame('padded', $psr7Request->headers->get('X-Padded'));

        $invalidNameRequest = self::rrRequest(headers: ['Host' => ['localhost'], 'Bad Header' => ['value']]);

        $nativeForwarded = new NativeSymfonyRequestFactory()->createRequest($invalidNameRequest, $server);
        $this->assertSame('value', $nativeForwarded->headers->get('Bad Header'));

        try {
            new Psr7SymfonyRequestFactory()->createRequest($invalidNameRequest, $server);
            $this->fail('psr7 strategy should reject an RFC-invalid header name');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testDeltaD7HostHeaderInjection(): void
    {
        $rrRequest = self::rrRequest(uri: 'https://host.example.com:443/path', headers: ['X-Other' => ['1']]);
        $server = GlobalState::enrichServerVars($rrRequest);

        $nativeRequest = new NativeSymfonyRequestFactory()->createRequest($rrRequest, $server);
        $psr7Request = new Psr7SymfonyRequestFactory()->createRequest($rrRequest, $server);

        $this->assertSame('host.example.com:443', $nativeRequest->headers->get('Host'));
        $this->assertSame('host.example.com', $psr7Request->headers->get('Host'));
    }

    /**
     * @param list<string> $excludedHeaders
     */
    private function assertRequestParity(Request $expected, Request $actual, array $excludedHeaders): void
    {
        $this->assertSame($expected->getMethod(), $actual->getMethod());
        $this->assertSame($expected->query->all(), $actual->query->all());
        $this->assertSame($expected->request->all(), $actual->request->all());
        $this->assertSame($expected->attributes->all(), $actual->attributes->all());
        $this->assertSame($expected->cookies->all(), $actual->cookies->all());
        $this->assertSame($expected->getContent(), $actual->getContent());

        $this->assertSame(
            $this->comparableHeaders($expected, $excludedHeaders),
            $this->comparableHeaders($actual, $excludedHeaders),
        );

        $this->assertSame($this->comparableServer($expected), $this->comparableServer($actual));
        $this->assertSame($expected->server->get('REQUEST_URI'), $actual->server->get('REQUEST_URI'));
        $this->assertSame($expected->server->get('QUERY_STRING'), $actual->server->get('QUERY_STRING'));
        $this->assertSame($expected->getUri(), $actual->getUri());

        $this->assertSame($this->describeFiles($expected->files->all()), $this->describeFiles($actual->files->all()));
    }

    /**
     * @param list<string> $excludedHeaders
     * @return array<string, array<int, string|null>>
     */
    private function comparableHeaders(Request $request, array $excludedHeaders): array
    {
        $headers = $request->headers->all();

        foreach ($excludedHeaders as $excludedHeader) {
            unset($headers[strtolower($excludedHeader)]);
        }

        return $headers;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function comparableServer(Request $request): array
    {
        $server = $request->server->all();

        unset($server['REQUEST_URI'], $server['QUERY_STRING'], $server['REQUEST_TIME'], $server['REQUEST_TIME_FLOAT']);

        return $server;
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function describeFiles(array $files): array
    {
        $described = [];

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $described[$key] = $this->describeFiles($file);
                continue;
            }

            if (!$file instanceof UploadedFile) {
                $described[$key] = $file === null ? null : get_debug_type($file);
                continue;
            }

            $content = null;
            $fileIsReadable = $file->getError() === \UPLOAD_ERR_OK && is_file($file->getPathname());
            if ($fileIsReadable) {
                $content = file_get_contents($file->getPathname());
            }

            $described[$key] = [
                'isUploadedFile' => true,
                'name'           => $file->getClientOriginalName(),
                'error'          => $file->getError(),
                'content'        => $content,
            ];
        }

        return $described;
    }

    private function createRequestThroughLegacyChain(RoadRunnerRequest $rrRequest, array $server): Request
    {
        $fakeGoridgeWorker = new class implements WorkerInterface {
            public function waitPayload(): ?Payload
            {
                return null;
            }

            public function respond(Payload $payload): void
            {
            }

            public function error(string $error): void
            {
            }

            public function stop(): void
            {
            }

            public function hasPayload(?string $class = null): bool
            {
                return false;
            }

            public function getPayload(?string $class = null): ?Payload
            {
                return null;
            }
        };

        $psr17Factory = new Psr17Factory();
        $psr7Worker = new PSR7Worker($fakeGoridgeWorker, $psr17Factory, $psr17Factory, $psr17Factory);

        $mapRequest = \Closure::bind(
            fn (RoadRunnerRequest $request, array $mapServer) => $this->mapRequest($request, $mapServer),
            $psr7Worker,
            PSR7Worker::class,
        );

        $psrRequest = $mapRequest($rrRequest, $server);

        return new \Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory()->createRequest($psrRequest);
    }

    /**
     * @param array<string, array<int, string>>              $headers
     * @param array<array-key, mixed>                        $uploads
     * @param array<string, mixed>                           $attributes
     */
    private static function rrRequest(
        string $method = 'GET',
        string $uri = 'http://localhost:8080/a/b?x=1&z=abc',
        ?array $headers = null,
        array  $uploads = [],
        string $body = '',
        bool   $parsed = false,
        ?array $attributes = null,
    ): RoadRunnerRequest
    {
        parse_str(parse_url($uri, \PHP_URL_QUERY) ?: '', $queryParameters);

        return new RoadRunnerRequest(
            remoteAddr: '10.0.0.5',
            protocol: 'HTTP/1.1',
            method: $method,
            uri: $uri,
            headers: $headers ?? ['Host' => ['localhost:8080'], 'User-Agent' => ['parity/1.0'], 'Cookie' => ['session=abc123']],
            cookies: ['session' => 'abc123'],
            uploads: $uploads,
            attributes: $attributes ?? [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => $parsed],
            query: $queryParameters,
            body: $body,
            parsed: $parsed,
        );
    }

    private static function createUploadFixtureFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'parity');
        file_put_contents($path, $content);
        self::$temporaryFiles[] = $path;

        return $path;
    }
}
