<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\ContextProvider\SourceContextProvider;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records where the last dump()/dd() ran so the worker error page can point at it.
 *
 * die()/exit() leave no trace: error_get_last() stays null and a shutdown function runs on an
 * unwound stack, so debug_backtrace() there is empty. dd() does leave one — it calls
 * VarDumper::dump() first, on an intact stack.
 *
 * See docs/specs/dump-capture.md.
 */
class DumpCapture implements ResetInterface
{
    private ?\Closure $handler = null;

    /** @var callable|null */
    private $forwardHandler = null;

    private ?SourceContextProvider $sourceContextProvider = null;
    private ?VarCloner $varCloner = null;
    private ?HtmlDumper $htmlDumper = null;

    private ?string $lastDumpLocation = null;
    private ?string $lastDumpFileLink = null;
    private string $renderedDumps = '';
    private int $renderedDumpCount = 0;

    public function __construct(
        private readonly bool               $debug,
        private readonly string             $projectDir,
        private readonly ?FileLinkFormatter $fileLinkFormatter = null,
        private readonly ?string            $dumpDestination = null,
    )
    {
    }

    public function installHandler(): void
    {
        if (!$this->isCapturingEnabled()) {
            return;
        }

        $this->handler ??= $this->createHandler();

        $previousHandler = $this->setVarDumperHandler($this->handler);
        $this->adoptForwardHandler($previousHandler);
    }

    public function getSnapshot(): ?DumpSnapshot
    {
        if ($this->lastDumpLocation === null) {
            return null;
        }

        $renderedDumps = $this->renderedDumps !== '' ? $this->renderedDumps : null;

        return new DumpSnapshot(
            $this->lastDumpLocation,
            $this->lastDumpFileLink,
            $renderedDumps,
            $this->dumpDestination,
        );
    }

    public function reset(): void
    {
        $this->lastDumpLocation = null;
        $this->lastDumpFileLink = null;
        $this->renderedDumps = '';
        $this->renderedDumpCount = 0;
    }

    private function createHandler(): \Closure
    {
        return function (mixed $variable, ?string $label = null): void {
            $sourceContextProvider = $this->getSourceContextProvider();
            $sourceContext = $sourceContextProvider->getContext();
            $this->recordDumpLocation($sourceContext);

            $this->forwardDump($variable, $label);
            $this->renderDump($variable, $label);
        };
    }

    /**
     * @param array<array-key, mixed>|null $sourceContext
     */
    private function recordDumpLocation(?array $sourceContext): void
    {
        if ($sourceContext === null) {
            return;
        }

        $file = $sourceContext['file_relative'] ?? $sourceContext['file'] ?? null;
        $line = $sourceContext['line'] ?? null;

        if (!\is_string($file) || !\is_int($line)) {
            return;
        }

        $this->lastDumpLocation = $file . ':' . $line;

        $fileLink = $sourceContext['file_link'] ?? null;
        $this->lastDumpFileLink = \is_string($fileLink) ? $fileLink : null;
    }

    private function forwardDump(mixed $variable, ?string $label): void
    {
        if ($this->forwardHandler === null) {
            $this->forwardToDefaultHandler($variable, $label);

            return;
        }

        ($this->forwardHandler)($variable, $label);
        $this->takeHandlerOwnershipBack();
    }

    /**
     * DebugBundle's registered handler replaces itself with its inner handler on first use, which
     * uninstalls ours; every other lazy handler may do the same.
     */
    private function takeHandlerOwnershipBack(): void
    {
        $currentHandler = $this->setVarDumperHandler($this->handler);
        $this->adoptForwardHandler($currentHandler);
    }

    /**
     * Lets VarDumper build its own env-configured handler instead of reimplementing
     * VarDumper::register(), then keeps that handler as the forward target.
     */
    private function forwardToDefaultHandler(mixed $variable, ?string $label): void
    {
        $this->setVarDumperHandler(null);

        try {
            VarDumper::dump($variable, $label);
        } finally {
            $defaultHandler = $this->setVarDumperHandler($this->handler);
            $this->adoptForwardHandler($defaultHandler);
        }
    }

    private function adoptForwardHandler(?callable $handler): void
    {
        if ($handler === $this->handler) {
            return;
        }

        $this->forwardHandler = $handler;
    }

    /**
     * VarDumper::setHandler() is a silent no-op while VAR_DUMPER_FORMAT is set, which is exactly
     * how a dump server is usually configured, so the key is lifted for the duration of the call.
     */
    private function setVarDumperHandler(?callable $handler): ?callable
    {
        $dumperFormat = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;
        unset($_SERVER['VAR_DUMPER_FORMAT']);

        $previousHandler = VarDumper::setHandler($handler);

        if ($dumperFormat !== null) {
            $_SERVER['VAR_DUMPER_FORMAT'] = $dumperFormat;
        }

        return $previousHandler;
    }

    private function renderDump(mixed $variable, ?string $label): void
    {
        $shouldRender = $this->shouldRenderDumps();

        if (!$shouldRender) {
            return;
        }

        $maxDumps = $this->getMaxRenderedDumps();

        if ($this->renderedDumpCount >= $maxDumps) {
            return;
        }

        $maxBytes = $this->getRenderedDumpMaxBytes();
        $renderedLength = \strlen($this->renderedDumps);

        if ($renderedLength >= $maxBytes) {
            return;
        }

        $varCloner = $this->getVarCloner();
        $data = $varCloner->cloneVar($variable);

        if ($label !== null) {
            $data = $data->withContext(['label' => $label]);
        }

        $htmlDumper = $this->getHtmlDumper();
        $rendered = $htmlDumper->dump($data, true);

        if (!\is_string($rendered)) {
            return;
        }

        $this->renderedDumps .= $rendered;
        ++$this->renderedDumpCount;
    }

    private function shouldRenderDumps(): bool
    {
        if ($this->dumpDestination !== null) {
            return false;
        }

        $dumperFormat = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;

        if ($dumperFormat === 'server') {
            return false;
        }

        if (!\is_string($dumperFormat)) {
            return true;
        }

        $formatScheme = parse_url($dumperFormat, \PHP_URL_SCHEME);

        return $formatScheme !== 'tcp';
    }

    private function isCapturingEnabled(): bool
    {
        return $this->debug && class_exists(VarDumper::class);
    }

    private function getSourceContextProvider(): SourceContextProvider
    {
        $this->sourceContextProvider ??= new SourceContextProvider(
            null,
            $this->projectDir,
            $this->fileLinkFormatter,
            $this->getBacktraceLimit(),
        );

        return $this->sourceContextProvider;
    }

    private function getVarCloner(): VarCloner
    {
        $this->varCloner ??= new VarCloner();

        return $this->varCloner;
    }

    private function getHtmlDumper(): HtmlDumper
    {
        $this->htmlDumper ??= new HtmlDumper();

        return $this->htmlDumper;
    }

    private function getBacktraceLimit(): int
    {
        return 12;
    }

    private function getMaxRenderedDumps(): int
    {
        return 5;
    }

    private function getRenderedDumpMaxBytes(): int
    {
        return 262144;
    }
}
