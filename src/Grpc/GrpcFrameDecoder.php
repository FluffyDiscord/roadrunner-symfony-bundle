<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use FluffyDiscord\RoadRunnerBundle\Exception\Grpc\GrpcFrameDecodingException;

class GrpcFrameDecoder
{
    public function decode(string $header): GrpcFrame
    {
        try {
            $decoded = json_decode($header, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new GrpcFrameDecodingException('gRPC frame header is not valid JSON: ' . $jsonException->getMessage(), previous: $jsonException);
        }

        if (!is_array($decoded)) {
            throw new GrpcFrameDecodingException('gRPC frame header must be a JSON object');
        }

        $serviceName = $decoded['service'] ?? null;
        $methodName = $decoded['method'] ?? null;
        $rawContext = $decoded['context'] ?? [];

        if (!is_string($serviceName) || !is_string($methodName)) {
            throw new GrpcFrameDecodingException('gRPC frame header must carry string "service" and "method" keys');
        }

        if (!is_array($rawContext)) {
            throw new GrpcFrameDecodingException('gRPC frame header "context" must be an object');
        }

        return new GrpcFrame($serviceName, $methodName, $this->normalizeMetadata($rawContext));
    }

    /**
     * @param array<mixed> $rawContext
     * @return array<string, list<string>>
     */
    private function normalizeMetadata(array $rawContext): array
    {
        $metadata = [];

        foreach ($rawContext as $key => $values) {
            if (!is_string($key)) {
                throw new GrpcFrameDecodingException('gRPC metadata keys must be strings');
            }

            $metadata[$key] = $this->normalizeMetadataValues($key, $values);
        }

        return $metadata;
    }

    /**
     * @return list<string>
     */
    private function normalizeMetadataValues(string $key, mixed $values): array
    {
        if (is_string($values)) {
            return [$values];
        }

        if (!is_array($values)) {
            throw new GrpcFrameDecodingException(sprintf('gRPC metadata "%s" must be a list of strings', $key));
        }

        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new GrpcFrameDecodingException(sprintf('gRPC metadata "%s" must be a list of strings', $key));
            }

            $normalized[] = $value;
        }

        return $normalized;
    }
}
