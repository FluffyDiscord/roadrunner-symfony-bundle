<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Warmup\LearnedManifestWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/** See docs/specs/worker-warmup.md §9 (U12 + missing-manifest no-op). */
class LearnedManifestWarmerTest extends BaseTestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = sys_get_temp_dir() . '/rr-warmup-learned-test-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
        parent::tearDown();
    }

    /**
     * @param list<string> $classes
     * @param list<string> $files
     */
    private function makeStorage(array $classes = [], array $files = []): WarmupManifestStorage
    {
        $storage = new WarmupManifestStorage($this->manifestPath, new ParameterBag(['container.build_id' => 'build-1']));
        if ($classes !== [] || $files !== []) {
            $storage->write($classes, $files);
        }

        return $storage;
    }

    public function testMissingManifestIsANoOp(): void
    {
        $warmer = new class($this->makeStorage()) extends LearnedManifestWarmer {
            public bool $preloadCalled = false;

            protected function preloadClasses(array $classes): void
            {
                $this->preloadCalled = true;
            }
        };

        $warmer->warmup();

        self::assertFalse($warmer->preloadCalled);
    }

    public function testPreloadsClassesButSkipsFileCompilationUnderFileCache(): void
    {
        $existingFile = __FILE__ . '';
        $storage = $this->makeStorage(['App\\Foo'], [$existingFile]);

        $warmer = new class($storage) extends LearnedManifestWarmer {
            /** @var list<string> */
            public array $preloadedClasses = [];

            /** @var list<string> */
            public array $compiledFiles = [];

            protected function preloadClasses(array $classes): void
            {
                $this->preloadedClasses = $classes;
            }

            protected function compileFile(string $file): void
            {
                $this->compiledFiles[] = $file;
            }

            protected function getFileCacheIni(): string
            {
                // Simulates opcache.file_cache pointing at a directory (spec ADR-4);
                // the real comparison logic in supportsFileCompilation() runs.
                return '/tmp/opcache-file-cache';
            }
        };

        $warmer->warmup();

        self::assertSame(['App\\Foo'], $warmer->preloadedClasses, 'class preload must still run');
        self::assertSame([], $warmer->compiledFiles, 'file compilation must be skipped under opcache.file_cache');
    }

    public function testCompilesManifestFilesWithoutFileCache(): void
    {
        $notYetIncluded = __DIR__ . '/Fixtures/build/App_KernelTestContainer.preload.php';
        $storage = $this->makeStorage(['App\\Foo'], [$notYetIncluded, '/nonexistent/file.php']);

        $warmer = new class($storage) extends LearnedManifestWarmer {
            /** @var list<string> */
            public array $compiledFiles = [];

            protected function preloadClasses(array $classes): void
            {
            }

            protected function compileFile(string $file): void
            {
                $this->compiledFiles[] = $file;
            }

            protected function getFileCacheIni(): string
            {
                return '';
            }
        };

        $warmer->warmup();

        self::assertSame([$notYetIncluded], $warmer->compiledFiles, 'existing not-yet-included files compile; missing files skipped');
    }
}
