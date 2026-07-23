<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Dumper\Preloader;

/**
 * Replays the learned manifest at boot: symbols via Symfony's Preloader (autoloader
 * driven, idempotent — opcache_compile_file() on class files would early-bind and
 * later fatal with "Cannot redeclare" on multi-class files), cache-dir files via
 * opcache_compile_file(). See docs/specs/worker-warmup.md ADR-3, ADR-4.
 */
class LearnedManifestWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private readonly WarmupManifestStorage $storage,
        private readonly ?LoggerInterface      $logger = null,
    )
    {
    }

    public function warmup(): void
    {
        $manifest = $this->storage->read();
        if ($manifest === null) {
            $this->logger?->debug('RoadRunner warmup: no learned manifest yet; skipping.');

            return;
        }

        $this->preloadClasses($manifest['classes']);

        if (!$this->supportsFileCompilation()) {
            return;
        }

        $alreadyIncluded = array_flip(get_included_files());

        foreach ($manifest['files'] as $file) {
            if (isset($alreadyIncluded[$file]) || !is_file($file)) {
                continue;
            }

            $this->compileFile($file);
        }
    }

    protected function compileFile(string $file): void
    {
        try {
            @opcache_compile_file($file);
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<string> $classes
     */
    protected function preloadClasses(array $classes): void
    {
        Preloader::preload($classes);
    }

    private function supportsFileCompilation(): bool
    {
        if (!function_exists('opcache_compile_file')) {
            $this->logger?->debug('RoadRunner warmup: opcache_compile_file unavailable; skipping cache-dir file compilation.');

            return false;
        }

        // Boot-compiling volatile cache-pool files while opcache.file_cache is active
        // measurably degrades first requests (spec ADR-4); the class preload above is
        // still beneficial, so only this step is skipped.
        if ($this->getFileCacheIni() !== '') {
            $this->logger?->debug('RoadRunner warmup: opcache.file_cache active; skipping cache-dir file compilation.');

            return false;
        }

        return true;
    }

    protected function getFileCacheIni(): string
    {
        return (string) ini_get('opcache.file_cache');
    }
}
