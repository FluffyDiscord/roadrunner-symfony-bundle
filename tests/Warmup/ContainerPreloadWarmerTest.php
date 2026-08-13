<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Warmup\ContainerPreloadWarmer;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * See docs/specs/worker-warmup.md §9 (U9-U11). The fixture pins the generated
 * preload-file format (spec ADR-9): if a Symfony release reformats the dump,
 * testExtractsClassListFromGeneratedFormat fails loudly instead of the warmer
 * silently no-opping in production.
 */
class ContainerPreloadWarmerTest extends BaseTestCase
{
    private function makeStorageWithoutManifest(): WarmupManifestStorage
    {
        return new WarmupManifestStorage('/nonexistent/warmup.manifest.json', new ParameterBag(['container.build_id' => 'build-1']));
    }

    public function testExtractsClassListFromGeneratedFormat(): void
    {
        $warmer = new class(__DIR__ . '/Fixtures/build', $this->makeStorageWithoutManifest()) extends ContainerPreloadWarmer {
            /** @var list<string> */
            public array $preloadedClasses = [];

            protected function preloadClasses(array $classes): void
            {
                $this->preloadedClasses = $classes;
            }
        };

        $warmer->warmup();

        self::assertSame(
            ['App\\Kernel', 'App\\Controller\\HomepageController', 'Symfony\\Component\\HttpFoundation\\Response'],
            $warmer->preloadedClasses,
        );
    }

    public function testFailsOpenWithoutPreloadFile(): void
    {
        $warmer = new class('/nonexistent-build-dir', $this->makeStorageWithoutManifest()) extends ContainerPreloadWarmer {
            /** @var list<string> */
            public array $preloadedClasses = [];

            protected function preloadClasses(array $classes): void
            {
                $this->preloadedClasses = $classes;
            }
        };

        $warmer->warmup();

        self::assertSame([], $warmer->preloadedClasses);
    }

    public function testSkippedWhenLearnedManifestExists(): void
    {
        $manifestPath = sys_get_temp_dir() . '/rr-warmup-preload-test-' . bin2hex(random_bytes(4)) . '.json';
        $storage = new WarmupManifestStorage($manifestPath, new ParameterBag(['container.build_id' => 'build-1']));
        $storage->write(['App\\Learned'], []);

        try {
            $warmer = new class(__DIR__ . '/Fixtures/build', $storage) extends ContainerPreloadWarmer {
                /** @var list<string> */
                public array $preloadedClasses = ['untouched'];

                protected function preloadClasses(array $classes): void
                {
                    $this->preloadedClasses = $classes;
                }
            };

            $warmer->warmup();

            self::assertSame(['untouched'], $warmer->preloadedClasses, 'parser must not run when a manifest exists');
        } finally {
            @unlink($manifestPath);
        }
    }
}
