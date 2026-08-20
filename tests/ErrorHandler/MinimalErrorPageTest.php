<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\ErrorHandler;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\DumpSnapshot;
use FluffyDiscord\RoadRunnerBundle\ErrorHandler\MinimalErrorPage;
use PHPUnit\Framework\TestCase;

/**
 * @see \FluffyDiscord\RoadRunnerBundle\ErrorHandler\MinimalErrorPage
 */
class MinimalErrorPageTest extends TestCase
{
    public function testRendersStatusAndEscapesMessage(): void
    {
        $html = MinimalErrorPage::render(500, [
            'message' => '<b>boom</b> & "quote"',
            'file'    => '/app/src/X.php',
            'line'    => 42,
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Internal Server Error', $html);
        // message is HTML-escaped, never injected raw
        $this->assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>boom</b>', $html);
        $this->assertStringContainsString('/app/src/X.php:42', $html);
    }

    public function testRendersGenericPageWhenErrorIsNull(): void
    {
        $html = MinimalErrorPage::render(500, null);

        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('terminated', $html);
        $this->assertStringContainsString('die() or exit()', $html);
        $this->assertStringContainsString('no file or line', $html);
        $this->assertStringNotContainsString('class="loc"', $html);
    }

    public function testUsesDetailWhenErrorIsNull(): void
    {
        $html = MinimalErrorPage::render(500, null, 'RuntimeException: kaboom in /app/x.php');

        $this->assertStringContainsString('kaboom', $html);
    }

    public function testRendersDumpSectionWithLinkAndDumpHtml(): void
    {
        $snapshot = new DumpSnapshot(
            'src/Controller/X.php:42',
            'phpstorm://open?file=/app/src/Controller/X.php&line=42',
            '<pre class="sf-dump">dumped value</pre>',
        );

        $html = MinimalErrorPage::render(500, null, null, $snapshot);

        $this->assertStringContainsString('href="phpstorm://open?file=/app/src/Controller/X.php&amp;line=42"', $html);
        $this->assertStringContainsString('>src/Controller/X.php:42</a>', $html);
        $this->assertStringContainsString('<pre class="sf-dump">dumped value</pre>', $html);
    }

    public function testRendersDumpDestinationInsteadOfTheDumpWhenAServerIsConfigured(): void
    {
        $snapshot = new DumpSnapshot('src/Controller/X.php:42', null, null, 'tcp://buggregator:9912');

        $html = MinimalErrorPage::render(500, null, null, $snapshot);

        $this->assertStringContainsString('src/Controller/X.php:42', $html);
        $this->assertStringContainsString('tcp://buggregator:9912', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    public function testTruncatesOverlongMessage(): void
    {
        $long = str_repeat('A', MinimalErrorPage::MESSAGE_MAX + 1000);
        $html = MinimalErrorPage::render(500, ['message' => $long]);

        $this->assertStringContainsString('[truncated]', $html);
        // the rendered run of A's is bounded near MESSAGE_MAX, not the full oversized input
        $this->assertLessThan(MinimalErrorPage::MESSAGE_MAX + 200, substr_count($html, 'A'));
    }

    public function testRendersWithoutErrorNoticesWhenErrorArrayPartial(): void
    {
        // message present but no file/line — must not emit a bogus location element
        $html = MinimalErrorPage::render(500, ['message' => 'partial']);

        $this->assertStringContainsString('partial', $html);
        $this->assertStringNotContainsString('class="loc"', $html);
    }
}
