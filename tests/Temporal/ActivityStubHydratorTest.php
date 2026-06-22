<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\ActivityStubHydrator;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingActivity;
use Temporal\Activity\ActivityOptions;

/** UT-001/002 — the attribute-to-ActivityOptions mapping and the retry-null branching. */
class ActivityStubHydratorTest extends BaseTestCase
{
    private function options(ActivityStub $stub): ActivityOptions
    {
        $method = new \ReflectionMethod(ActivityStubHydrator::class, 'options');

        $options = $method->invoke(null, $stub);
        self::assertInstanceOf(ActivityOptions::class, $options);

        return $options;
    }

    private function seconds(\DateInterval $interval): int
    {
        return (new \DateTimeImmutable('@0'))->add($interval)->getTimestamp();
    }

    public function testFullMappingWithExplicitRetry(): void
    {
        $options = $this->options(new ActivityStub(
            GreetingActivity::class,
            queue: 'gallery-download',
            startToClose: '30 minutes',
            heartbeat: 120,
            retryAttempts: 3,
        ));

        self::assertSame('gallery-download', $options->taskQueue);
        self::assertSame(1800, $this->seconds($options->startToCloseTimeout));
        self::assertSame(120, $this->seconds($options->heartbeatTimeout));
        self::assertNotNull($options->retryOptions);
        self::assertSame(3, $options->retryOptions->maximumAttempts);
        self::assertSame(2.0, $options->retryOptions->backoffCoefficient, 'unset backoff = SDK default 2.0');
    }

    public function testQueueOmittedInheritsWorkflowQueue(): void
    {
        $options = $this->options(new ActivityStub(GreetingActivity::class, startToClose: 300, retryAttempts: 3));

        self::assertNull($options->taskQueue);
        self::assertSame(300, $this->seconds($options->startToCloseTimeout));
    }

    public function testNoRetryFieldsLeavesRetryOptionsNull(): void
    {
        $options = $this->options(new ActivityStub(GreetingActivity::class, startToClose: 60));

        self::assertNull($options->retryOptions, 'no retry field set => Temporal default, not a bundle default');
    }

    public function testZeroAttemptsIsUnlimited(): void
    {
        $options = $this->options(new ActivityStub(GreetingActivity::class, startToClose: 60, retryAttempts: 0));

        self::assertNotNull($options->retryOptions);
        self::assertSame(0, $options->retryOptions->maximumAttempts);
    }

    public function testDateIntervalDurationAccepted(): void
    {
        $options = $this->options(new ActivityStub(GreetingActivity::class, startToClose: new \DateInterval('PT3M')));

        self::assertSame(180, $this->seconds($options->startToCloseTimeout));
    }
}
