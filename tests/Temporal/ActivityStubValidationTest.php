<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\Compiler\TemporalWorkerPass;
use FluffyDiscord\RoadRunnerBundle\Exception\InvalidActivityStubException;
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInitializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubGoodWorkflow;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubMissingTimeoutWorkflow;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubNoHydrateWorkflow;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\StubTypedWorkflow;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** UT-005 — the compiler-pass build-time guard for #[ActivityStub] properties. */
class ActivityStubValidationTest extends BaseTestCase
{
    private function process(string $workflowClass): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(TemporalWorkerInitializer::class, new Definition(TemporalWorkerInitializer::class));
        $container->setDefinition($workflowClass, new Definition($workflowClass));

        (new TemporalWorkerPass())->process($container);

        return $container;
    }

    public function testGoodWorkflowPasses(): void
    {
        $container = $this->process(StubGoodWorkflow::class);

        self::assertTrue($container->getDefinition(StubGoodWorkflow::class)->hasTag('fluffy_discord.roadrunner.temporal.workflow'));
    }

    public function testTypedStubPropertyFails(): void
    {
        $this->expectException(InvalidActivityStubException::class);
        $this->expectExceptionMessageMatches('/must be untyped/');

        $this->process(StubTypedWorkflow::class);
    }

    public function testMissingBaseAndTraitFails(): void
    {
        $this->expectException(InvalidActivityStubException::class);
        $this->expectExceptionMessageMatches('/never hydrated/');

        $this->process(StubNoHydrateWorkflow::class);
    }

    public function testMissingCloseTimeoutFails(): void
    {
        $this->expectException(InvalidActivityStubException::class);
        $this->expectExceptionMessageMatches('/startToClose or scheduleToClose/');

        $this->process(StubMissingTimeoutWorkflow::class);
    }
}
