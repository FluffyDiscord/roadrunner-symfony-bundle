<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Psr\Log\AbstractLogger;

/** See docs/specs/worker-warmup.md §9 (U4-U8, U7b). */
class WarmupManifestStorageTest extends BaseTestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/rr-warmup-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/{.,}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->workDir);
        }
        parent::tearDown();
    }

    private function makeStorage(string $buildId = 'build-1', ?string $path = null): WarmupManifestStorage
    {
        return new WarmupManifestStorage($path ?? $this->workDir . '/warmup.manifest.json', new ParameterBag(['container.build_id' => $buildId]));
    }

    public function testRoundTripStampsVersionAndBuildId(): void
    {
        $storage = $this->makeStorage();
        $storage->write(['App\\Foo', 'App\\Bar'], ['/cache/pools/x']);

        self::assertSame(
            ['classes' => ['App\\Foo', 'App\\Bar'], 'files' => ['/cache/pools/x']],
            $storage->read(),
        );

        $rawData = json_decode((string) file_get_contents($this->workDir . '/warmup.manifest.json'), true);
        self::assertSame(1, $rawData['version']);
        self::assertSame('build-1', $rawData['build_id']);
    }

    public function testWritesMergeAsUnion(): void
    {
        $storage = $this->makeStorage();
        $storage->write(['App\\Foo'], ['/cache/a']);
        $storage->write(['App\\Bar', 'App\\Foo'], ['/cache/b', '/cache/a']);

        self::assertSame(
            ['classes' => ['App\\Foo', 'App\\Bar'], 'files' => ['/cache/a', '/cache/b']],
            $storage->read(),
        );
    }

    public function testRereadSeesLatestWrite(): void
    {
        $writer = $this->makeStorage();
        $reader = $this->makeStorage();

        $writer->write(['App\\Foo'], []);
        self::assertSame(['App\\Foo'], $reader->read()['classes'] ?? null);

        $writer->write(['App\\Bar'], []);
        self::assertSame(['App\\Foo', 'App\\Bar'], $reader->read()['classes'] ?? null);
    }

    public function testFiltersAnonymousClassNames(): void
    {
        $storage = $this->makeStorage();
        $storage->write(['App\\Foo', "Iface@anonymous /var/cache/pool/xyz:3\$24a1"], []);

        self::assertSame(['App\\Foo'], $storage->read()['classes'] ?? null);
    }

    public function testFiltersNonUtf8SymbolsAndFiles(): void
    {
        $storage = $this->makeStorage();
        $storage->write(['App\\Foo', "Bad\xA9Class"], ["/cache/ok", "/cache/bad\xA9"]);

        self::assertSame(
            ['classes' => ['App\\Foo'], 'files' => ['/cache/ok']],
            $storage->read(),
            'non-UTF-8 names must not poison the manifest',
        );
    }

    public function testRejectsCorruptOrMismatchedManifests(): void
    {
        $manifestPath = $this->workDir . '/warmup.manifest.json';
        mkdir($this->workDir, 0777, true);

        file_put_contents($manifestPath, 'not json');
        self::assertNull($this->makeStorage()->read());

        file_put_contents($manifestPath, json_encode(['version' => 99, 'build_id' => 'build-1', 'classes' => [], 'files' => []]));
        self::assertNull($this->makeStorage()->read());

        file_put_contents($manifestPath, json_encode(['version' => 1, 'build_id' => 'other-build', 'classes' => [], 'files' => []]));
        self::assertNull($this->makeStorage()->read());
        self::assertFalse($this->makeStorage()->exists());

        file_put_contents($manifestPath, json_encode(['version' => 1, 'build_id' => 'build-1', 'classes' => 'nope', 'files' => []]));
        self::assertNull($this->makeStorage()->read());
    }

    public function testCreatesMissingDirectoryRecursively(): void
    {
        $storage = $this->makeStorage(path: $this->workDir . '/nested/deeper/warmup.manifest.json');
        $storage->write(['App\\Foo'], []);

        self::assertSame(['App\\Foo'], $storage->read()['classes'] ?? null);

        @unlink($this->workDir . '/nested/deeper/warmup.manifest.json');
        @rmdir($this->workDir . '/nested/deeper');
        @rmdir($this->workDir . '/nested');
    }

    public function testUnwritableTargetDegradesWithSingleWarning(): void
    {
        $records = new \ArrayObject();
        $logger = new class($records) extends AbstractLogger {
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records->append((string) $level);
            }
        };

        $storage = new WarmupManifestStorage('/proc/definitely-not-writable/warmup.manifest.json', new ParameterBag(['container.build_id' => 'build-1']), $logger);
        $storage->write(['App\\Foo'], []);
        $storage->write(['App\\Bar'], []);

        self::assertSame(['warning'], (array) $records, 'exactly one warning for repeated failures');
    }
}
