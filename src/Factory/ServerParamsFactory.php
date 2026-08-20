<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;

class ServerParamsFactory
{
    /**
     * @return non-empty-array<string, mixed>
     */
    public function createServerParams(RoadRunnerRequest $rrRequest): array
    {
        $server = [
            'REQUEST_METHOD'     => $rrRequest->method,
            'REQUEST_URI'        => $rrRequest->uri,
            'SERVER_PROTOCOL'    => $rrRequest->protocol,
            'REMOTE_ADDR'        => $rrRequest->getRemoteAddr(),
            'REQUEST_TIME'       => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_USER_AGENT'    => '',
        ];

        $httpHost = $this->getHttpHost($rrRequest->uri);
        if ($httpHost !== null) {
            $server['HTTP_HOST'] = $httpHost;
        }

        foreach ($rrRequest->headers as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $value = implode(', ', $values);

            $isContentHeader = $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH';
            if ($isContentHeader) {
                $server[$key] = $value;
                continue;
            }

            $server['HTTP_' . $key] = $value;
        }

        return $server;
    }

    private function getHttpHost(string $uri): ?string
    {
        $uriParts = parse_url($uri);
        $uriIsParsable = is_array($uriParts);
        if (!$uriIsParsable) {
            return null;
        }

        $host = $uriParts['host'] ?? null;
        $hostIsUsable = is_string($host) && $host !== '';
        if (!$hostIsUsable) {
            return null;
        }

        $port = $uriParts['port'] ?? null;
        $portIsUsable = is_int($port);
        if (!$portIsUsable) {
            return $host;
        }

        return $host . ':' . $port;
    }
}
