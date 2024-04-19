<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Basically a copy of BinaryFileResponse->sendContent()
 * but yielding to behave like a generator
 */
class BinaryFileResponseWrapper
{
    public static function wrap(BinaryFileResponse $response, Request $request): \Generator
    {
        $response->prepare($request);

        $reflectionClass = new \ReflectionClass($response);
        $maxlen = $reflectionClass->getProperty("maxlen")->getValue($response);
        $offset = $reflectionClass->getProperty("offset")->getValue($response);
        $chunkSize = $reflectionClass->getProperty("chunkSize")->getValue($response);
        $deleteFileAfterSend = $reflectionClass->getProperty("deleteFileAfterSend")->getValue($response);

        try {
            if (!$response->isSuccessful()) {
                return;
            }

            $file = fopen($response->getFile()->getPathname(), "r");

            if ($maxlen === 0) {
                return;
            }

            if ($offset !== 0) {
                fseek($file, $offset);
            }

            $length = $maxlen;
            while ($length && !feof($file)) {
                $read = $length > $chunkSize || 0 > $length ? $chunkSize : $length;

                if (false === $data = fread($file, $read)) {
                    break;
                }

                while ("" !== $data) {
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

            fclose($file);
        } finally {
            if ($deleteFileAfterSend && is_file($response->getFile()->getPathname())) {
                unlink($response->getFile()->getPathname());
            }
        }
    }
}