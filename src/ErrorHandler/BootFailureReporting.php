<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

use Sentry\State\HubInterface as SentryHubInterface;

trait BootFailureReporting
{
    abstract protected function getBootFailureSentryHub(): ?SentryHubInterface;

    protected function getBootFailureLabel(): string
    {
        return 'BOOT FAILURE';
    }

    protected function reportBootFailure(\Throwable $throwable): void
    {
        @ini_set('display_errors', 'stderr');

        $this->logError($this->getBootFailureLabel() . ': ' . $throwable);
        $this->captureBootFailure($throwable);
    }

    protected function captureBootFailure(\Throwable $throwable): void
    {
        $sentryHub = $this->getBootFailureSentryHub();

        if ($sentryHub === null) {
            return;
        }

        try {
            $sentryHub->captureException($throwable);
            $sentryHub->getClient()?->flush();
        } catch (\Throwable) {
        }
    }

    protected function logError(string $message): void
    {
        @fwrite(\STDERR, '[roadrunner-symfony] ' . $message . "\n");
    }
}
