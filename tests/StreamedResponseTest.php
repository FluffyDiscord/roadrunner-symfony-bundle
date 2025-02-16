<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Factory\StreamedResponseWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;

class StreamedResponseTest extends TestCase
{
    public static function responseProvider(): array
    {
        $callback = static function () {
            echo "start";
            echo " middle ";
            echo "end";
        };

        $generator = static function (): \Generator {
            yield "start";
            yield " middle ";
            yield "end";
        };

        $vanillaSymfonyResponse = new StreamedResponse($callback);
        $generatorSymfonyResponse = new StreamedResponse($generator);

        return [
            "Use vanilla callback"      => [$vanillaSymfonyResponse, "start middle end", false],
            "Use generator as callback" => [$generatorSymfonyResponse, "start middle end", true],
        ];
    }

    #[DataProvider("responseProvider")]
    public function testVanillaResponse(
        StreamedResponse $symfonyResponse,
        string           $expected,
        bool             $isGenerator,
    ): void
    {
        ob_start();
        $symfonyResponse->sendContent();
        $content = ob_get_clean();

        if ($isGenerator) {
            $this->assertSame(
                hash("xxh128", ""),
                hash("xxh128", $content),
            );
        } else {
            $this->assertSame(
                hash("xxh128", $expected),
                hash("xxh128", $content),
            );
        }
    }

    #[DataProvider("responseProvider")]
    public function testKernelWrappedBundleResponseWrapper(
        StreamedResponse $symfonyResponse,
        string           $expected,
    ): void
    {
        if (Kernel::MAJOR_VERSION >= 6) {
            $callback = $symfonyResponse->getCallback();
        } else {
            $ref = new \ReflectionClass($symfonyResponse);
            $callback = $ref->getProperty("callback")->getValue($symfonyResponse);
        }

        // simulate double kernel callback
        $symfonyResponse->setCallback(static function () use ($callback) {
            $callback();
        });

        $content = implode("", iterator_to_array(StreamedResponseWrapper::wrap($symfonyResponse)));

        $this->assertSame(
            hash("xxh128", $expected),
            hash("xxh128", $content),
        );
    }

    #[DataProvider("responseProvider")]
    public function testPureBundleResponseWrapper(
        StreamedResponse $symfonyResponse,
        string           $expected,
    ): void
    {
        // simulate situation where Kernel
        // did not wrap the response
        $content = implode("", iterator_to_array(StreamedResponseWrapper::wrap($symfonyResponse)));

        $this->assertSame(
            hash("xxh128", $expected),
            hash("xxh128", $content),
        );
    }
}