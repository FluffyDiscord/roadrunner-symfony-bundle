<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

/**
 * Implementations are collected via the "fluffy_discord.road_runner.worker_warmer" tag
 * (autoconfigured) and executed by WorkerWarmupRunner while the worker boots, before
 * RoadRunner marks it ready. See docs/specs/worker-warmup.md.
 */
interface WorkerWarmerInterface
{
    public function warmup(): void;
}
