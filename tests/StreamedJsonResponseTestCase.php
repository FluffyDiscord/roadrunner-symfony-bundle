<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests;

use FluffyDiscord\RoadRunnerBundle\Factory\StreamedJsonResponseWrapper;
use FluffyDiscord\RoadRunnerBundle\Tests\Attributes\SkipForSymfonyVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

class StreamedJsonResponseTestCase extends BaseTestCase
{
    public static function responseProvider(): array
    {
        $generator = static function (): \Generator {
            yield ["id" => 1];
            yield ["id" => 2];
            yield ["id" => 3];
        };

        $symfonyResponse = new StreamedJsonResponse([
            "items" => $generator(),
        ]);

        return [
            [$symfonyResponse, '{"items":[{"id":1},{"id":2},{"id":3}]}'],
        ];
    }

    #[SkipForSymfonyVersion("<", "6.4")]
    #[DataProvider("responseProvider")]
    public function testVanillaResponse(
        StreamedJsonResponse $symfonyResponse,
        string               $expected,
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

    #[SkipForSymfonyVersion("<", "6.4")]
    #[DataProvider("responseProvider")]
    public function testBundleResponseWrapper(
        StreamedJsonResponse $symfonyResponse,
        string               $expected,
    ): void
    {
        $content = implode("", iterator_to_array(StreamedJsonResponseWrapper::wrap($symfonyResponse)));

        $this->assertSame(
            hash("xxh128", $expected),
            hash("xxh128", $content),
        );
    }
}