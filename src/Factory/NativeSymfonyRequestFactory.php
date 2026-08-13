<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class NativeSymfonyRequestFactory implements SymfonyRequestFactoryInterface
{
    public function createRequest(RoadRunnerRequest $rrRequest, array $server): Request
    {
        $uriParts = parse_url($rrRequest->uri);
        if ($uriParts === false) {
            throw new \InvalidArgumentException(sprintf('Unable to parse request URI "%s"', $rrRequest->uri));
        }

        $scheme = strtolower($uriParts['scheme'] ?? '');
        $host = strtolower($uriParts['host'] ?? '');
        $path = $uriParts['path'] ?? '';
        $queryString = $uriParts['query'] ?? '';

        $server['SERVER_NAME'] = $host;
        $server['SERVER_PORT'] = $uriParts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $server['REQUEST_URI'] = $queryString === '' ? $path : $path . '?' . $queryString;
        $server['QUERY_STRING'] = $queryString;
        $server['REQUEST_METHOD'] = $rrRequest->method;

        if ($scheme === 'https') {
            $server['HTTPS'] = 'on';
        }

        $parsedBody = [];
        if ($rrRequest->parsed) {
            $parsedBody = (array) json_decode($rrRequest->body, true, 512, \JSON_THROW_ON_ERROR);
        }

        $request = new Request(
            $rrRequest->query,
            $parsedBody,
            $rrRequest->attributes,
            $rrRequest->cookies,
            $this->createUploadedFiles($rrRequest->uploads),
            $server,
            $rrRequest->body,
        );

        $request->headers->add($rrRequest->headers);

        return $request;
    }

    /**
     * @param array<array-key, mixed> $uploads
     * @return array<array-key, mixed>
     */
    private function createUploadedFiles(array $uploads): array
    {
        $files = [];

        foreach ($uploads as $key => $upload) {
            $uploadIsArray = is_array($upload);
            if (!$uploadIsArray) {
                continue;
            }

            $clientOriginalName = $upload['name'] ?? null;
            $leafHasName = is_string($clientOriginalName);
            if (!$leafHasName) {
                $files[$key] = $this->createUploadedFiles($upload);
                continue;
            }

            $temporaryPath = $upload['tmpName'] ?? '';
            $temporaryPathIsString = is_string($temporaryPath);
            if (!$temporaryPathIsString) {
                $temporaryPath = '';
            }

            $mimeType = $upload['mime'] ?? '';
            $mimeTypeIsUsable = is_string($mimeType) && $mimeType !== '';
            if (!$mimeTypeIsUsable) {
                $mimeType = null;
            }

            $errorCode = $upload['error'] ?? \UPLOAD_ERR_OK;
            $errorCodeIsInt = is_int($errorCode);
            if (!$errorCodeIsInt) {
                $errorCode = \UPLOAD_ERR_OK;
            }

            $files[$key] = new UploadedFile($temporaryPath, $clientOriginalName, $mimeType, $errorCode, true);
        }

        return $files;
    }
}
