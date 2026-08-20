<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestRecorder;
use FluffyDiscord\RoadRunnerBundle\Warmup\WarmupManifestStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Spiral\RoadRunner\Environment\Mode;

/** See docs/specs/worker-warmup.md §9 (U13-U14). */
class WarmupManifestRecorderTest extends BaseTestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = sys_get_temp_dir() . '/rr-warmup-recorder-test-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
        parent::tearDown();
    }

    private function makeStorage(): WarmupManifestStorage
    {
        return new WarmupManifestStorage($this->manifestPath, new ParameterBag(['container.build_id' => 'build-1']));
    }

    public function testFirstEventRecordsFullCurrentSets(): void
    {
        $recorder = new WarmupManifestRecorder($this->makeStorage(), '/definitely-not-a-cache-dir', 5);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        $manifest = $this->makeStorage()->read();
        self::assertNotNull($manifest, 'the first response must be recorded — it carries the cold-load graph');
        self::assertContains(WarmupManifestRecorder::class, $manifest['classes']);
    }

    public function testRecordsNothingWhenTheWorkerIsNotPersistent(): void
    {
        $recorder = new WarmupManifestRecorder($this->makeStorage(), '/definitely-not-a-cache-dir', 5, persistentWorker: false);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        self::assertNull($this->makeStorage()->read(), 'a debug-mode pool never replays a manifest, so learning one is wasted disk writes');
    }

    public function testRecordsSymbolsLoadedBetweenResponses(): void
    {
        $recorder = new WarmupManifestRecorder($this->makeStorage(), '/definitely-not-a-cache-dir', 5);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        // A class loaded between two responses — exactly what a cold page archetype does.
        eval('namespace FluffyDiscord\\RoadRunnerBundle\\Tests\\Warmup\\Generated; class LoadedBetweenResponses {}');
        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        $manifest = $this->makeStorage()->read();
        self::assertNotNull($manifest);
        self::assertContains(
            'FluffyDiscord\\RoadRunnerBundle\\Tests\\Warmup\\Generated\\LoadedBetweenResponses',
            $manifest['classes'],
        );
    }

    public function testUngrownSetsSkipTheWrite(): void
    {
        $recorder = new WarmupManifestRecorder($this->makeStorage(), '/definitely-not-a-cache-dir', 5);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        // Backdate: filemtime() has 1-second granularity, so a same-second rewrite
        // would otherwise be invisible and the assertion vacuous.
        touch($this->manifestPath, time() - 10);
        clearstatcache();
        $backdatedTime = filemtime($this->manifestPath);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));
        clearstatcache();

        self::assertSame($backdatedTime, filemtime($this->manifestPath), 'no growth => no rewrite');
    }

    public function testRecordsOnlyCacheDirFiles(): void
    {
        $includedFiles = get_included_files();
        $cacheDirPrefix = dirname($includedFiles[0]);
        $recorder = new WarmupManifestRecorder($this->makeStorage(), $cacheDirPrefix, 5);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        $manifest = $this->makeStorage()->read();
        self::assertNotNull($manifest);
        self::assertNotSame([], $manifest['files'], 'files under the cache-dir prefix must be recorded');
        foreach ($manifest['files'] as $file) {
            self::assertStringStartsWith($cacheDirPrefix, $file, 'files outside the cache dir must never be recorded (early-binding fatal)');
        }
    }

    public function testStopsAfterLearnRequestsAndIgnoresNonHttp(): void
    {
        $recorder = new WarmupManifestRecorder($this->makeStorage(), '/definitely-not-a-cache-dir', 1);

        $recorder(new WorkerResponseSentEvent(Mode::MODE_JOBS));
        self::assertFalse(is_file($this->manifestPath), 'non-http events must be ignored');

        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));
        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        eval('namespace FluffyDiscord\\RoadRunnerBundle\\Tests\\Warmup\\Generated; class LoadedAfterWindow {}');
        $recorder(new WorkerResponseSentEvent(Mode::MODE_HTTP));

        $manifest = $this->makeStorage()->read();
        self::assertNotNull($manifest, 'the in-window response must have been recorded');
        self::assertNotContains(
            'FluffyDiscord\\RoadRunnerBundle\\Tests\\Warmup\\Generated\\LoadedAfterWindow',
            $manifest['classes'],
            'recording past the learn window is forbidden',
        );
    }
}
