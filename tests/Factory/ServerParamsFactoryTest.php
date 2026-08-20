<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Factory;

use FluffyDiscord\RoadRunnerBundle\Factory\ServerParamsFactory;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;

class ServerParamsFactoryTest extends BaseTestCase
{
    private ServerParamsFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new ServerParamsFactory();
    }

    public function testRequestValuesArePresent(): void
    {
        $rrRequest = new RoadRunnerRequest(
            remoteAddr: '203.0.113.7',
            protocol: 'HTTP/2.0',
            method: 'POST',
            uri: 'https://example.com:8443/a/b?x=1',
        );

        $server = $this->factory->createServerParams($rrRequest);

        $this->assertSame('POST', $server['REQUEST_METHOD']);
        $this->assertSame('https://example.com:8443/a/b?x=1', $server['REQUEST_URI']);
        $this->assertSame('HTTP/2.0', $server['SERVER_PROTOCOL']);
        $this->assertSame('203.0.113.7', $server['REMOTE_ADDR']);
        $this->assertSame('example.com:8443', $server['HTTP_HOST']);
        $this->assertIsInt($server['REQUEST_TIME']);
        $this->assertIsFloat($server['REQUEST_TIME_FLOAT']);
    }

    public function testProxyIpAddressAttributeWinsOverRemoteAddress(): void
    {
        $rrRequest = new RoadRunnerRequest(
            remoteAddr: '10.0.0.1',
            attributes: ['ipAddress' => '198.51.100.4'],
        );

        $server = $this->factory->createServerParams($rrRequest);

        $this->assertSame('198.51.100.4', $server['REMOTE_ADDR']);
    }

    public function testHeadersBecomeServerParameters(): void
    {
        $rrRequest = new RoadRunnerRequest(
            headers: [
                'Host'           => ['example.com'],
                'X-Forwarded-For' => ['198.51.100.4', '203.0.113.7'],
                'Content-Type'   => ['application/json'],
                'Content-Length' => ['12'],
                'User-Agent'     => ['curl/8.0'],
            ],
        );

        $server = $this->factory->createServerParams($rrRequest);

        $this->assertSame('198.51.100.4, 203.0.113.7', $server['HTTP_X_FORWARDED_FOR']);
        $this->assertSame('application/json', $server['CONTENT_TYPE']);
        $this->assertSame('12', $server['CONTENT_LENGTH']);
        $this->assertSame('curl/8.0', $server['HTTP_USER_AGENT']);
        $this->assertArrayNotHasKey('HTTP_CONTENT_TYPE', $server);
    }

    public function testUserAgentDefaultsToEmptyString(): void
    {
        $server = $this->factory->createServerParams(new RoadRunnerRequest());

        $this->assertSame('', $server['HTTP_USER_AGENT']);
    }

    public function testHostIsOmittedForUnparseableUri(): void
    {
        $rrRequest = new RoadRunnerRequest(uri: '/relative/path');

        $server = $this->factory->createServerParams($rrRequest);

        $this->assertArrayNotHasKey('HTTP_HOST', $server);
    }

    public function testEnvironmentIsNotInherited(): void
    {
        $_SERVER['HTTP_PROXY'] = 'http://attacker.example.com';
        $_SERVER['APP_SECRET_FOR_TEST'] = 'leaked';
        $_SERVER['SCRIPT_NAME'] = '/app/public/index.php';

        try {
            $server = $this->factory->createServerParams(new RoadRunnerRequest());
        } finally {
            unset($_SERVER['HTTP_PROXY'], $_SERVER['APP_SECRET_FOR_TEST'], $_SERVER['SCRIPT_NAME']);
        }

        $this->assertArrayNotHasKey('HTTP_PROXY', $server);
        $this->assertArrayNotHasKey('APP_SECRET_FOR_TEST', $server);
        $this->assertArrayNotHasKey('SCRIPT_NAME', $server);
        $this->assertArrayNotHasKey('argv', $server);
    }
}
