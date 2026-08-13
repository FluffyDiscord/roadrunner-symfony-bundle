<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

class Psr7SymfonyRequestFactory implements SymfonyRequestFactoryInterface
{
    private HttpFoundationFactoryInterface $httpFoundationFactory;
    private Psr17Factory                   $psrFactory;

    public function __construct(?HttpFoundationFactoryInterface $httpFoundationFactory = null)
    {
        $this->httpFoundationFactory = $httpFoundationFactory ?? new HttpFoundationFactory();
        $this->psrFactory = new Psr17Factory();
    }

    public function createRequest(RoadRunnerRequest $rrRequest, array $server): Request
    {
        return $this->httpFoundationFactory->createRequest($this->createPsrRequest($rrRequest, $server));
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function createPsrRequest(RoadRunnerRequest $rrRequest, array $server): ServerRequestInterface
    {
        $psrRequest = new ServerRequest(
            $rrRequest->method,
            $rrRequest->uri,
            $rrRequest->headers,
            $rrRequest->body === '' ? null : $rrRequest->body,
            $this->fetchProtocolVersion($rrRequest->protocol),
            $server,
        );

        $psrRequest = $psrRequest
            ->withCookieParams($rrRequest->cookies)
            ->withQueryParams($rrRequest->query)
            ->withUploadedFiles($this->wrapUploads($rrRequest->uploads));

        foreach ($rrRequest->attributes as $name => $value) {
            $psrRequest = $psrRequest->withAttribute($name, $value);
        }

        if ($rrRequest->parsed) {
            $psrRequest = $psrRequest->withParsedBody($rrRequest->getParsedBody());
        }

        return $psrRequest;
    }

    /**
     * @param array<array-key, mixed> $uploads
     * @return array<array-key, mixed>
     */
    private function wrapUploads(array $uploads): array
    {
        $wrapped = [];

        foreach ($uploads as $index => $upload) {
            $uploadIsArray = is_array($upload);
            if (!$uploadIsArray) {
                continue;
            }

            $clientOriginalName = $upload['name'] ?? null;
            $leafHasName = is_string($clientOriginalName);
            if (!$leafHasName) {
                $wrapped[$index] = $this->wrapUploads($upload);
                continue;
            }

            $errorCode = $upload['error'] ?? \UPLOAD_ERR_OK;
            $errorCodeIsInt = is_int($errorCode);
            if (!$errorCodeIsInt) {
                $errorCode = \UPLOAD_ERR_OK;
            }

            $temporaryPath = $upload['tmpName'] ?? '';
            $temporaryPathIsString = is_string($temporaryPath);
            if (!$temporaryPathIsString) {
                $temporaryPath = '';
            }

            $leafIsReadable = $errorCode === \UPLOAD_ERR_OK && $temporaryPath !== '';
            if ($leafIsReadable) {
                $stream = $this->psrFactory->createStreamFromFile($temporaryPath);
            } else {
                $stream = $this->psrFactory->createStream();
            }

            $size = $upload['size'] ?? 0;
            $sizeIsInt = is_int($size);
            if (!$sizeIsInt) {
                $size = 0;
            }

            $mimeType = $upload['mime'] ?? '';
            $mimeTypeIsString = is_string($mimeType);
            if (!$mimeTypeIsString) {
                $mimeType = '';
            }

            $wrapped[$index] = $this->psrFactory->createUploadedFile($stream, $size, $errorCode, $clientOriginalName, $mimeType);
        }

        return $wrapped;
    }

    private function fetchProtocolVersion(string $protocol): string
    {
        $version = substr($protocol, 5);

        if ($version === '2.0') {
            return '2';
        }

        $versionIsAllowed = in_array($version, $this->getAllowedProtocolVersions(), true);
        if (!$versionIsAllowed) {
            return '1.1';
        }

        return $version;
    }

    /**
     * @return list<string>
     */
    private function getAllowedProtocolVersions(): array
    {
        return ['1.0', '1.1', '2'];
    }
}
