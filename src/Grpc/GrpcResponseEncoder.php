<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use Google\Protobuf\Any;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Status;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\ResponseTrailers;

class GrpcResponseEncoder
{
    public function encodeSuccessHeaders(ResponseHeaders $headers, ResponseTrailers $trailers): string
    {
        return $this->encodeDocument($this->buildHeaderDocument($headers, $trailers));
    }

    public function encodeError(GRPCExceptionInterface $exception, ResponseHeaders $headers, ResponseTrailers $trailers): string
    {
        $document = $this->buildHeaderDocument($headers, $trailers);
        $document['error'] = $this->encodeStatusMessage($exception->getCode(), $exception->getMessage(), $exception->getDetails());

        return $this->encodeDocument($document);
    }

    public function encodeStatus(int $code, string $message): string
    {
        return $this->encodeDocument(['error' => $this->encodeStatusMessage($code, $message, [])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeaderDocument(ResponseHeaders $headers, ResponseTrailers $trailers): array
    {
        $document = [];
        $headerCount = $headers->count();
        $trailerCount = $trailers->count();

        if ($headerCount > 0) {
            $document['headers'] = iterator_to_array($headers->getIterator());
        }

        if ($trailerCount > 0) {
            $document['trailers'] = iterator_to_array($trailers->getIterator());
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function encodeDocument(array $document): string
    {
        if ($document === []) {
            return '{}';
        }

        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $details
     */
    private function encodeStatusMessage(int $code, string $message, array $details): string
    {
        $packedDetails = [];

        foreach ($details as $detail) {
            if (!$detail instanceof Message) {
                continue;
            }

            $any = new Any();
            $any->pack($detail);
            $packedDetails[] = $any;
        }

        $status = new Status([
            'code'    => $code,
            'message' => $message,
            'details' => $packedDetails,
        ]);

        return base64_encode($status->serializeToString());
    }
}
