<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * JSON manifest of symbols/files real traffic loaded, replayed at worker boot.
 * JSON, not executable PHP: a PHP manifest read via include would be opcache-cached
 * (stale re-reads under validate_timestamps=0) and would execute attacker-writable
 * code if manifest_path is relocated. See docs/specs/worker-warmup.md ADR-8.
 */
class WarmupManifestStorage
{
    private const int VERSION = 1;

    private bool $writeFailureLogged = false;

    public function __construct(
        private readonly string                 $manifestPath,
        private readonly ?ParameterBagInterface $parameterBag = null,
        private readonly ?LoggerInterface       $logger = null,
    )
    {
    }

    private function getContainerBuildId(): string
    {
        // container.build_id is added by PhpDumper at dump time; it is not available
        // during container compilation, hence the runtime lookup.
        try {
            $buildId = $this->parameterBag?->get('container.build_id');

            return is_string($buildId) ? $buildId : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array{classes: list<string>, files: list<string>}|null
     */
    public function read(): ?array
    {
        try {
            if (!is_file($this->manifestPath)) {
                return null;
            }

            $raw = file_get_contents($this->manifestPath);
            if ($raw === false) {
                return null;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return null;
            }

            if (($data['version'] ?? null) !== self::VERSION || ($data['build_id'] ?? null) !== $this->getContainerBuildId()) {
                return null;
            }

            $classes = $data['classes'] ?? null;
            $files = $data['files'] ?? null;
            if (!is_array($classes) || !is_array($files)) {
                return null;
            }

            return [
                'classes' => array_values(array_filter($classes, 'is_string')),
                'files' => array_values(array_filter($files, 'is_string')),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function exists(): bool
    {
        return $this->read() !== null;
    }

    /**
     * @param list<string> $classes
     * @param list<string> $files
     */
    public function write(array $classes, array $files): bool
    {
        try {
            // Runtime-generated symbols are unlearnable: anonymous classes cannot be
            // autoloaded, and some vendor-generated class names carry raw non-UTF-8
            // bytes that would make json_encode fail for the whole manifest.
            $isLearnable = static fn(string $symbol) => !str_contains($symbol, '@anonymous') && preg_match('//u', $symbol) === 1;
            $newClasses = array_values(array_filter($classes, $isLearnable));
            $newFiles = array_values(array_filter($files, static fn(string $file) => preg_match('//u', $file) === 1));

            $current = $this->read() ?? ['classes' => [], 'files' => []];
            $merged = [
                'version' => self::VERSION,
                'build_id' => $this->getContainerBuildId(),
                'classes' => array_values(array_unique(array_merge($current['classes'], $newClasses))),
                'files' => array_values(array_unique(array_merge($current['files'], $newFiles))),
            ];

            $manifestDirectory = dirname($this->manifestPath);
            if (!is_dir($manifestDirectory) && !@mkdir($manifestDirectory, 0777, true) && !is_dir($manifestDirectory)) {
                $this->logWriteFailure('directory could not be created');

                return false;
            }

            // Unique temp name in the manifest's own directory: concurrent workers never
            // share a temp file, and rename() stays on one filesystem (atomic).
            $tempPath = $manifestDirectory . '/.warmup.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';

            $json = json_encode($merged);
            if ($json === false || @file_put_contents($tempPath, $json) === false) {
                $this->logWriteFailure('temp file write failed');

                return false;
            }

            if (!@rename($tempPath, $this->manifestPath)) {
                @unlink($tempPath);
                $this->logWriteFailure('rename failed');

                return false;
            }

            return true;
        } catch (\Throwable $throwable) {
            $this->logWriteFailure($throwable->getMessage());

            return false;
        }
    }

    private function logWriteFailure(string $reason): void
    {
        if ($this->writeFailureLogged) {
            return;
        }

        $this->writeFailureLogged = true;
        $this->logger?->warning('RoadRunner warmup: manifest "{path}" is not writable ({reason}); learning disabled, generic warmers still run.', [
            'path' => $this->manifestPath,
            'reason' => $reason,
        ]);
    }
}
