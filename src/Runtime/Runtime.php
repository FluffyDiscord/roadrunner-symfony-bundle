<?php

namespace FluffyDiscord\RoadRunnerBundle\Runtime;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;
use Symfony\Component\Yaml\Yaml;

class Runtime extends SymfonyRuntime
{
    private string $rrConfigPath;
    private string $projectDir;

    /**
     * @param array{rr_config_path?: string, project_dir?: string|null, debug?: bool|null, env?: string|null, disable_dotenv?: bool|null, prod_envs?: array<string>|null, dotenv_path?: string|null, test_envs?: array<string>|null, use_putenv?: bool|null} $options
     */
    public function __construct(array $options = [])
    {
        $this->rrConfigPath = $options['rr_config_path'] ?? '.rr.yaml';
        $this->projectDir = $options['project_dir'] ?? (string) getcwd();
        parent::__construct($options);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        $rrMode = getenv('RR_MODE');
        if ($application instanceof KernelInterface && $rrMode !== false) {
            return new Runner($application, $rrMode, $this->resolveRuntimeMode($rrMode));
        }

        return parent::getRunner($application);
    }

    private function resolveRuntimeMode(string $rrMode): string
    {
        $poolDebug = false;
        $rrConfigContent = @file_get_contents($this->projectDir . '/' . $this->rrConfigPath);
        if ($rrConfigContent !== false) {
            /** @var array<string, mixed> $rrConfig */
            $rrConfig = Yaml::parse($rrConfigContent) ?? [];

            $sectionKey = match ($rrMode) {
                'http' => 'http',
                'centrifuge' => 'centrifuge',
                default => null,
            };

            if ($sectionKey !== null) {
                /** @var array{pool?: array{debug?: bool}} $section */
                $section = $rrConfig[$sectionKey] ?? [];
                $poolDebug = (bool) ($section['pool']['debug'] ?? false);
            }
        }

        $workerVar = $poolDebug ? '0' : '1';

        return match ($rrMode) {
            'http' => 'web=1&worker=' . $workerVar,
            default => 'worker=' . $workerVar,
        };
    }
}
