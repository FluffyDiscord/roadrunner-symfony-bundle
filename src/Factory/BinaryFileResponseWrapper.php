<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;

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

        $tempFileObject = null;
        if(Kernel::MAJOR_VERSION >= 7) {
            $tempFileObject = $reflectionClass->getProperty("tempFileObject")->getValue($response);
        }

        $maxlen = $reflectionClass->getProperty("maxlen")->getValue($response);
        $offset = $reflectionClass->getProperty("offset")->getValue($response);

        $chunkSize = 16 * 1024;
        if(Kernel::MAJOR_VERSION >= 6) {
            $chunkSize = $reflectionClass->getProperty("chunkSize")->getValue($response);
        }

        $deleteFileAfterSend = $reflectionClass->getProperty("deleteFileAfterSend")->getValue($response);

        try {
            if (!$response->isSuccessful()) {
                return yield "";
            }

            if (0 === $maxlen) {
                return yield "";
            }

            if ($tempFileObject) {
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
            if (null === $tempFileObject && $deleteFileAfterSend && is_file($response->getFile()->getPathname())) {
                unlink($response->getFile()->getPathname());
            }
        }
    }
}