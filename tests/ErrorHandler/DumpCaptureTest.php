<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\ErrorHandler;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\VarDumper\VarDumper;

/**
 * @see \FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpCapture
 * @see docs/specs/dump-capture.md §9 (TC-D1..TC-D8)
 */
class DumpCaptureTest extends TestCase
{
    private ?string $originalDumperFormat = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDumperFormat = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;
        unset($_SERVER['VAR_DUMPER_FORMAT']);
        VarDumper::setHandler(null);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['VAR_DUMPER_FORMAT']);
        VarDumper::setHandler(null);

        if ($this->originalDumperFormat !== null) {
            $_SERVER['VAR_DUMPER_FORMAT'] = $this->originalDumperFormat;
        }

        parent::tearDown();
    }

    /** TC-D1: an already installed handler keeps receiving every dump, exactly once. */
    public function testForwardsToThePreviousHandler(): void
    {
        $forwarded = [];
        VarDumper::setHandler(static function (mixed $variable, ?string $label = null) use (&$forwarded): void {
            $forwarded[] = $variable;
        });

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        dump('forwarded value');

        $this->assertSame(['forwarded value'], $forwarded);
    }

    /** TC-D2: with no previous handler, VarDumper's own default still produces the dump. */
    public function testForwardsToVarDumperDefaultWhenNoHandlerWasInstalled(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        ob_start();
        dump('default handler value');
        dump('second value');
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('default handler value', $output);
        $this->assertStringContainsString('second value', $output);
        $this->assertSame(1, substr_count($output, 'default handler value'));
    }

    /** TC-D3: VarDumper::setHandler() is a no-op while VAR_DUMPER_FORMAT is set — lift it, restore it. */
    public function testInstallsUnderVarDumperFormatAndRestoresIt(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        ob_start();
        dump('captured anyway');
        ob_get_clean();

        $snapshot = $dumpCapture->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertSame('html', $_SERVER['VAR_DUMPER_FORMAT']);
    }

    /** TC-D4: the location is the caller's file:line, relative to the project dir, with an IDE link. */
    public function testRecordsCallerLocationAndFileLink(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();
        $dumpCapture = $this->makeDumpCapture(new FileLinkFormatter('phpstorm://open?file=%f&line=%l'));
        $dumpCapture->installHandler();

        ob_start();
        dump('located'); $expectedLine = __LINE__;
        ob_get_clean();

        $snapshot = $dumpCapture->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertSame('tests/ErrorHandler/DumpCaptureTest.php:' . $expectedLine, $snapshot->location);
        $this->assertNotNull($snapshot->fileLink);
        $this->assertStringContainsString('phpstorm://open?file=', $snapshot->fileLink);
        $this->assertStringContainsString('line=' . $expectedLine, $snapshot->fileLink);
    }

    /** TC-D5: a dump() in a loop must not grow the rescue page without bound. */
    public function testBoundsTheRenderedDumps(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        ob_start();
        for ($index = 0; $index < 50; ++$index) {
            dump('value ' . $index);
        }
        $lastLine = __LINE__ - 2;
        ob_get_clean();

        $snapshot = $dumpCapture->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertNotNull($snapshot->renderedDumps);
        $this->assertSame('tests/ErrorHandler/DumpCaptureTest.php:' . $lastLine, $snapshot->location);
        $this->assertLessThan(300000, \strlen($snapshot->renderedDumps));
        $this->assertStringNotContainsString('value 49', $snapshot->renderedDumps);
    }

    /** TC-D6: outside debug nothing is installed and nothing is captured. */
    public function testCapturesNothingOutsideDebug(): void
    {
        $dumpCapture = $this->makeDumpCapture(debug: false);
        $dumpCapture->installHandler();

        $handlerAfterInstall = VarDumper::setHandler(null);

        $this->assertNull($handlerAfterInstall);
        $this->assertNull($dumpCapture->getSnapshot());
    }

    /** TC-D7: DebugBundle's handler swaps itself out on first use — we must take ownership back. */
    public function testSurvivesASelfReplacingHandler(): void
    {
        $forwarded = [];
        $innerHandler = static function (mixed $variable, ?string $label = null) use (&$forwarded): void {
            $forwarded[] = $variable;
        };
        VarDumper::setHandler(static function (mixed $variable, ?string $label = null) use ($innerHandler): void {
            VarDumper::setHandler($innerHandler);
            $innerHandler($variable, $label);
        });

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        dump('first');
        dump('second'); $secondLine = __LINE__;

        $snapshot = $dumpCapture->getSnapshot();

        $this->assertSame(['first', 'second'], $forwarded);
        $this->assertNotNull($snapshot);
        $this->assertSame('tests/ErrorHandler/DumpCaptureTest.php:' . $secondLine, $snapshot->location);
    }

    /** TC-D8: with a dump server configured the dump is not rendered again into the page. */
    public function testSkipsRenderingWhenADumpServerIsConfigured(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();
        $dumpCapture = $this->makeDumpCapture(dumpDestination: 'tcp://buggregator:9912');
        $dumpCapture->installHandler();

        ob_start();
        dump('goes to buggregator');
        ob_get_clean();

        $snapshot = $dumpCapture->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->renderedDumps);
        $this->assertSame('tcp://buggregator:9912', $snapshot->dumpDestination);
    }

    public function testResetForgetsTheCapturedRequest(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        ob_start();
        dump('first request');
        ob_get_clean();

        $dumpCapture->reset();

        $this->assertNull($dumpCapture->getSnapshot());
    }

    public function testLogSuffixNamesTheLocation(): void
    {
        $this->routeDefaultDumpsToOutputBuffer();

        $dumpCapture = $this->makeDumpCapture();
        $dumpCapture->installHandler();

        ob_start();
        dump('logged'); $expectedLine = __LINE__;
        ob_get_clean();

        $snapshot = $dumpCapture->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertSame(
            '; last dump ran at tests/ErrorHandler/DumpCaptureTest.php:' . $expectedLine,
            $snapshot->getLogSuffix(),
        );
    }

    /** CliDumper writes to php://stdout, which ob_start() cannot swallow; HtmlDumper uses php://output. */
    private function routeDefaultDumpsToOutputBuffer(): void
    {
        $_SERVER['VAR_DUMPER_FORMAT'] = 'html';
    }

    private function makeDumpCapture(
        ?FileLinkFormatter $fileLinkFormatter = null,
        bool               $debug = true,
        ?string            $dumpDestination = null,
    ): DumpCapture
    {
        $projectDir = \dirname(__DIR__, 2);

        return new DumpCapture($debug, $projectDir, $fileLinkFormatter, $dumpDestination);
    }
}
