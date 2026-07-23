<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Dumper\Preloader;

/**
 * First-boot fallback: preloads the class list Symfony already dumped into the
 * build dir. The generated preload file cannot be included directly — it returns
 * early under the cli SAPI RoadRunner workers run in — so the class list is
 * extracted from its stable "$classes[] = '...';" lines. Fail-open: format drift
 * means a no-op, pinned by a fixture test. See docs/specs/worker-warmup.md ADR-9.
 */
class ContainerPreloadWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private readonly string                $buildDir,
        private readonly WarmupManifestStorage $storage,
        private readonly ?LoggerInterface      $logger = null,
    )
    {
    }

    public function warmup(): void
    {
        if ($this->storage->exists()) {
            $this->logger?->debug('RoadRunner warmup: learned manifest present; container preload fallback skipped.');

            return;
        }

        $classes = $this->extractPreloadClasses();
        if ($classes === []) {
            $this->logger?->debug('RoadRunner warmup: no container preload file or no classes extracted; skipping.');

            return;
        }

        $this->preloadClasses($classes);
    }

    /**
     * @param list<string> $classes
     */
    protected function preloadClasses(array $classes): void
    {
        Preloader::preload($classes);
    }

    /**
     * @return list<string>
     */
    private function extractPreloadClasses(): array
    {
        $preloadFiles = glob($this->buildDir . '/*.preload.php');
        if ($preloadFiles === false || $preloadFiles === []) {
            return [];
        }

        if (count($preloadFiles) > 1) {
            $this->logger?->debug('RoadRunner warmup: multiple preload files in the build dir; using "{file}".', [
                'file' => $preloadFiles[0],
            ]);
        }

        $source = file_get_contents($preloadFiles[0]);
        if ($source === false) {
            return [];
        }

        if (preg_match_all("/^\\\$classes\\[\\] = '([^']+)';$/m", $source, $matches) === false) {
            return [];
        }

        return $matches[1];
    }
}
