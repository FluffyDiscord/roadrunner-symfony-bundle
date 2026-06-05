<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class BinaryFileResponseWrapper
{
    public static function wrap(BinaryFileResponse $response, Request $request): \Generator
    {
        $response->prepare($request);

        $reflectionClass = new \ReflectionClass($response);

        $tempFileObject = $reflectionClass->getProperty("tempFileObject")->getValue($response);

        /** @var int $maxlen */
        $maxlen = $reflectionClass->getProperty("maxlen")->getValue($response);
        /** @var int $offset */
        $offset = $reflectionClass->getProperty("offset")->getValue($response);
        /** @var int $chunkSize */
        $chunkSize = $reflectionClass->getProperty("chunkSize")->getValue($response);
        /** @var bool $deleteFileAfterSend */
        $deleteFileAfterSend = $reflectionClass->getProperty("deleteFileAfterSend")->getValue($response);

        try {
            if (!$response->isSuccessful()) {
                return yield "";
            }

            if (0 === $maxlen) {
                return yield "";
            }

            if ($tempFileObject instanceof \SplFileObject) {
                $file = $tempFileObject;
                $file->rewind();
            } else {
                $file = new \SplFileObject($response->getFile()->getPathname(), 'r');
            }

            if (0 !== $offset) {
                $file->fseek($offset);
            }

            $length = $maxlen;
            while ($length && !$file->eof()) {
                $read = $length > $chunkSize || 0 > $length ? $chunkSize : $length;

                if (false === $data = $file->fread($read)) {
                    break;
                }
                while ('' !== $data) {
                    try {
                        yield $data;
                    } catch (StreamStoppedException) {
                        break 2;
                    }

                    if (0 < $length) {
                        $length -= $read;
                    }
                    $data = substr($data, $read);
                }
            }
        } finally {
            if (!$tempFileObject instanceof \SplFileObject && $deleteFileAfterSend && is_file($response->getFile()->getPathname())) {
                unlink($response->getFile()->getPathname());
            }
        }
    }
}
