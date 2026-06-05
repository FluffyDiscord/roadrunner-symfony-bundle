<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use Symfony\Component\HttpFoundation\StreamedJsonResponse;

class StreamedJsonResponseWrapper
{
    public static function wrap(StreamedJsonResponse $response): \Generator
    {
        $reflectionClass = new \ReflectionClass($response);

        /** @var int $encodingOptions */
        $encodingOptions = $reflectionClass->getProperty("encodingOptions")->getValue($response);
        /** @var iterable<mixed> $data */
        $data = $reflectionClass->getProperty("data")->getValue($response);
        /** @var string $placeholder */
        $placeholder = $reflectionClass->getConstant("PLACEHOLDER");

        return self::stream($data, $encodingOptions, $placeholder);
    }

    /** @param iterable<mixed> $data */
    private static function stream(iterable $data, int $encodingOptions, string $placeholder): \Generator
    {
        $jsonEncodingOptions = \JSON_THROW_ON_ERROR | $encodingOptions;
        $keyEncodingOptions = $jsonEncodingOptions & ~\JSON_NUMERIC_CHECK;

        return self::streamData($data, $jsonEncodingOptions, $keyEncodingOptions, $placeholder);
    }

    private static function streamData(mixed $data, int $jsonEncodingOptions, int $keyEncodingOptions, string $placeholder): \Generator
    {
        if (\is_array($data)) {
            foreach (self::streamArray($data, $jsonEncodingOptions, $keyEncodingOptions, $placeholder) as $item) {
                yield $item;
            }

            return;
        }

        if (is_iterable($data) && !$data instanceof \JsonSerializable) {
            foreach (self::streamIterable($data, $jsonEncodingOptions, $keyEncodingOptions, $placeholder) as $item) {
                yield $item;
            }

            return;
        }

        yield json_encode($data, $jsonEncodingOptions);
    }

    /** @param array<mixed> $data */
    private static function streamArray(array $data, int $jsonEncodingOptions, int $keyEncodingOptions, string $placeholder): \Generator
    {
        $generators = [];

        array_walk_recursive($data, function (&$item, $key) use (&$generators, $placeholder) {
            if ($placeholder === $key) {
                $generators[] = $key;
            }

            if (\is_object($item)) {
                $generators[] = $item;
                $item = $placeholder;
            } elseif ($placeholder === $item) {
                $generators[] = $item;
            }
        });

        $jsonParts = explode('"' . $placeholder . '"', (string)json_encode($data, $jsonEncodingOptions));

        foreach ($generators as $index => $generator) {
            yield $jsonParts[$index];

            foreach (self::streamData($generator, $jsonEncodingOptions, $keyEncodingOptions, $placeholder) as $child) {
                yield $child;
            }
        }

        yield $jsonParts[array_key_last($jsonParts)];
    }

    /** @param iterable<mixed, mixed> $iterable */
    private static function streamIterable(iterable $iterable, int $jsonEncodingOptions, int $keyEncodingOptions, string $placeholder): \Generator
    {
        $isFirstItem = true;
        $startTag = '[';

        foreach ($iterable as $key => $item) {
            if ($isFirstItem) {
                $isFirstItem = false;
                if (0 !== $key) {
                    $startTag = '{';
                }

                yield $startTag;
            } else {
                yield ',';
            }

            if ('{' === $startTag) {
                /** @var int|string $key */
                yield json_encode((string)$key, $keyEncodingOptions) . ':';
            }

            foreach (self::streamData($item, $jsonEncodingOptions, $keyEncodingOptions, $placeholder) as $child) {
                yield $child;
            }
        }

        if ($isFirstItem) {
            yield '[';
        }

        yield '[' === $startTag ? ']' : '}';
    }
}