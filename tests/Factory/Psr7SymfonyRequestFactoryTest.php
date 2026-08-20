<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Factory;

use FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Psr\Http\Message\ServerRequestInterface;
use FluffyDiscord\RoadRunnerBundle\Factory\ServerParamsFactory;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

class Psr7SymfonyRequestFactoryTest extends BaseTestCase
{
    public function testCustomHttpFoundationFactoryReceivesEquivalentPsr7Request(): void
    {
        $capturedPsrRequest = null;
        $expectedResponse = new Request();

        $spyFactory = new class($capturedPsrRequest, $expectedResponse) implements HttpFoundationFactoryInterface {
            public function __construct(
                private ?ServerRequestInterface &$capturedPsrRequest,
                private readonly Request        $response,
            )
            {
            }

            public function createRequest(ServerRequestInterface $psrRequest, bool $streamed = false): Request
            {
                $this->capturedPsrRequest = $psrRequest;

                return $this->response;
            }

            public function createResponse(\Psr\Http\Message\ResponseInterface $psrResponse, bool $streamed = false): \Symfony\Component\HttpFoundation\Response
            {
                throw new \LogicException('not used');
            }
        };

        $rrRequest = new RoadRunnerRequest(
            remoteAddr: '10.0.0.5',
            protocol: 'HTTP/2.0',
            method: 'POST',
            uri: 'https://localhost/submit?q=1',
            headers: ['Host' => ['localhost'], 'Content-Type' => ['application/json']],
            cookies: ['session' => 's1'],
            attributes: [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => true, 'extra' => 'attr'],
            query: ['q' => '1'],
            body: '{"a":1}',
            parsed: true,
        );
        $server = new ServerParamsFactory()->createServerParams($rrRequest);

        $factory = new Psr7SymfonyRequestFactory($spyFactory);
        $result = $factory->createRequest($rrRequest, $server);

        $this->assertSame($expectedResponse, $result);
        $this->assertInstanceOf(ServerRequestInterface::class, $capturedPsrRequest);

        $this->assertSame('POST', $capturedPsrRequest->getMethod());
        $this->assertSame('https://localhost/submit?q=1', (string) $capturedPsrRequest->getUri());
        $this->assertSame('2', $capturedPsrRequest->getProtocolVersion());
        $this->assertSame(['q' => '1'], $capturedPsrRequest->getQueryParams());
        $this->assertSame(['session' => 's1'], $capturedPsrRequest->getCookieParams());
        $this->assertSame(['a' => 1], $capturedPsrRequest->getParsedBody());
        $this->assertSame('{"a":1}', (string) $capturedPsrRequest->getBody());
        $this->assertSame('application/json', $capturedPsrRequest->getHeaderLine('Content-Type'));
        $this->assertSame('attr', $capturedPsrRequest->getAttribute('extra'));
        $this->assertTrue($capturedPsrRequest->getAttribute(RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME));
        $this->assertSame($server, $capturedPsrRequest->getServerParams());
    }
}
