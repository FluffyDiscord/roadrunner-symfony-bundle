<?php

namespace FluffyDiscord\RoadRunnerBundle\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

readonly class RoadRunnerYamlConfigReader
{
    public function __construct(
        private string  $projectDir,
        private ?string $rrConfigPath,
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function readAll(): array
    {
        if ($this->rrConfigPath === null) {
            return [];
        }

        $content = @file_get_contents($this->projectDir . '/' . $this->rrConfigPath);

        if ($content === false) {
            return [];
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException) {
            return [];
        }

        if (!is_array($parsed)) {
            return [];
        }

        /** @var array<string, mixed> $parsed */
        return $this->expandEnvironmentPlaceholders($parsed);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSection(string $name): ?array
    {
        $all = $this->readAll();
        $section = $all[$name] ?? null;

        if (!is_array($section)) {
            return null;
        }

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function expandEnvironmentPlaceholders(array $values): array
    {
        $expanded = [];

        foreach ($values as $key => $value) {
            $expanded[$key] = $this->expandValue($value);
        }

        return $expanded;
    }

    private function expandValue(mixed $value): mixed
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->expandEnvironmentPlaceholders($value);
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?}/', $this->resolvePlaceholder(...), $value) ?? $value;
    }

    /**
     * @param array<int|string, string> $match
     */
    private function resolvePlaceholder(array $match): string
    {
        $environmentValue = getenv($match[1]);

        if ($environmentValue !== false) {
            return $environmentValue;
        }

        return $match[2] ?? '';
    }
}
