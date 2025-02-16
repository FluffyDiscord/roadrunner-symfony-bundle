<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Factory\BinaryFileResponseWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class BinaryFileResponseTestCase extends BaseTestCase
{
    public static function responseProvider(): array
    {
        $fileWithContent = __DIR__ . "/dummy/civic_renewal_forms.zip";
        $emptyFile = __DIR__ . "/dummy/empty.txt";

        return [
            "Whole and with range starting from zero"       => [new BinaryFileResponse($fileWithContent), file_get_contents($fileWithContent), 0, 6023],
            "Whole and with range starting from 4525 bytes" => [new BinaryFileResponse($fileWithContent), file_get_contents($fileWithContent), 4525, 14509],
            "Empty file"                                    => [new BinaryFileResponse($emptyFile), file_get_contents($emptyFile), 0],
        ];
    }

    #[DataProvider("responseProvider")]
    public function testVanillaResponse(
        BinaryFileResponse $symfonyResponse,
        string             $expected,
    ): void
    {
        ob_start();
        $symfonyResponse->sendContent();
        $content = ob_get_clean();

        $this->assertSame(
            hash("xxh128", $expected),
            hash("xxh128", $content),
        );
    }

    #[DataProvider("responseProvider")]
    public function testVanillaRangeResponse(
        BinaryFileResponse $symfonyResponse,
        string             $expected,
        int                $rangeStart,
        int                $rangeEnd,
    ): void
    {
        $request = Request::createFromGlobals();
        $request->headers->set("Range", "bytes={$rangeStart}-{$rangeEnd}");

        $symfonyResponse->prepare($request);

        ob_start();
        $symfonyResponse->sendContent();
        $content = ob_get_clean();

        $this->assertSame(
            hash("xxh128", substr($expected, $rangeStart, $rangeEnd - ($rangeStart - 1))),
            hash("xxh128", $content),
        );
    }

    #[DataProvider("responseProvider")]
    public function testBundleResponseWrapper(
        BinaryFileResponse $symfonyResponse,
        string             $expected,
    ): void
    {
        $content = implode("", iterator_to_array(BinaryFileResponseWrapper::wrap($symfonyResponse, Request::createFromGlobals())));

        $this->assertSame(
            hash("xxh128", $expected),
            hash("xxh128", $content),
        );
    }

    #[DataProvider("responseProvider")]
    public function testBundleRangeResponse(
        BinaryFileResponse $symfonyResponse,
        string             $expected,
        int                $rangeStart,
        int                $rangeEnd,
    ): void
    {
        $request = Request::createFromGlobals();
        $request->headers->set("Range", "bytes={$rangeStart}-{$rangeEnd}");

        $symfonyResponse->prepare($request);

        $content = implode("", iterator_to_array(BinaryFileResponseWrapper::wrap($symfonyResponse, $request)));

        $this->assertSame(
            hash("xxh128", substr($expected, $rangeStart, $rangeEnd - ($rangeStart - 1))),
            hash("xxh128", $content),
        );
    }
}