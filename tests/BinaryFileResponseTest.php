<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Factory\BinaryFileResponseWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class BinaryFileResponseTest extends TestCase
{
    public static function responseProvider(): array
    {
        $file = __DIR__ . "/dummy/civic_renewal_forms.zip";

        $symfonyResponse = new BinaryFileResponse($file);

        return [
            "Whole and with range starting from zero"       => [$symfonyResponse, file_get_contents($file), 0, 6023],
            "Whole and with range starting from 4525 bytes" => [$symfonyResponse, file_get_contents($file), 4525, 14509],
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

        $this->assertSame($expected, $content);
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

        $this->assertSame(substr($expected, $rangeStart, $rangeEnd - ($rangeStart - 1)), $content);
    }

    #[DataProvider("responseProvider")]
    public function testBundleResponseWrapper(
        BinaryFileResponse $symfonyResponse,
        string             $expected,
    ): void
    {
        $content = implode("", iterator_to_array(BinaryFileResponseWrapper::wrap($symfonyResponse, Request::createFromGlobals())));

        $this->assertSame($expected, $content);
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

        $content = implode("", iterator_to_array(BinaryFileResponseWrapper::wrap($symfonyResponse, Request::createFromGlobals())));

        $this->assertSame(substr($expected, $rangeStart, $rangeEnd - ($rangeStart - 1)), $content);
    }
}