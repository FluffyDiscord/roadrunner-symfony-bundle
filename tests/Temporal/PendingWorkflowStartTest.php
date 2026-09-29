<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Client\PendingWorkflowStart;
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncher;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\DefaultedWorkflowInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures\GreetingWorkflow;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\SearchAttributes\SearchAttributeKey;
use Temporal\Common\TypedSearchAttributes;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Workflow\WorkflowRunInterface;

/** UT-006/007 — the launcher seeds from #[WorkflowDefaults], of() is fresh per call, startOrSkip swallows AlreadyStarted. */
class PendingWorkflowStartTest extends BaseTestCase
{
    private function readProperty(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    public function testSeedsFromWorkflowDefaultsAttributeAndOverrides(): void
    {
        $launcher = new WorkflowLauncher($this->createStub(WorkflowClientInterface::class));

        $pending = $launcher->of(DefaultedWorkflowInterface::class);
        self::assertSame('billing', $this->readProperty($pending, 'queue'));
        self::assertSame(IdReusePolicy::AllowDuplicate, $this->readProperty($pending, 'reusePolicy'));

        $pending->queue('override');
        self::assertSame('override', $this->readProperty($pending, 'queue'), 'a fluent call overrides the attribute default');
    }

    public function testOfReturnsAFreshBuilderEachCall(): void
    {
        $launcher = new WorkflowLauncher($this->createStub(WorkflowClientInterface::class));

        $a = $launcher->of(GreetingWorkflow::class)->queue('q1');
        $b = $launcher->of(GreetingWorkflow::class)->queue('q2');

        self::assertNotSame($a, $b);
        self::assertSame('q1', $this->readProperty($a, 'queue'));
        self::assertSame('q2', $this->readProperty($b, 'queue'));
    }

    public function testStartReturnsTheRun(): void
    {
        $run = $this->createStub(WorkflowRunInterface::class);

        $client = $this->createStub(WorkflowClientInterface::class);
        $client->method('newWorkflowStub')->willReturn(new \stdClass());
        $client->method('start')->willReturn($run);

        $launcher = new WorkflowLauncher($client);

        self::assertSame($run, $launcher->of(GreetingWorkflow::class)->id('x')->start('arg'));
    }

    private function startAndCaptureOptions(PendingWorkflowStart $pending, WorkflowClientInterface $client): WorkflowOptions
    {
        $capturedOptions = null;
        $client->method('newWorkflowStub')->willReturnCallback(
            static function (string $workflowInterface, WorkflowOptions $options) use (&$capturedOptions): object {
                $capturedOptions = $options;

                return new \stdClass();
            },
        );

        $pending->start();

        self::assertInstanceOf(WorkflowOptions::class, $capturedOptions);

        return $capturedOptions;
    }

    public function testSearchAttributesAndMemoReachTheWorkflowOptions(): void
    {
        $client = $this->createStub(WorkflowClientInterface::class);
        $pending = (new WorkflowLauncher($client))->of(GreetingWorkflow::class)
            ->searchAttributes(['WebsiteId' => 'site-1'])
            ->memo(['source' => 'catalog']);

        $options = $this->startAndCaptureOptions($pending, $client);

        self::assertSame(['WebsiteId' => 'site-1'], $options->searchAttributes);
        self::assertSame(['source' => 'catalog'], $options->memo);
    }

    public function testTypedSearchAttributesReachTheWorkflowOptions(): void
    {
        $websiteId = SearchAttributeKey::forKeyword('WebsiteId');
        $client = $this->createStub(WorkflowClientInterface::class);
        $pending = (new WorkflowLauncher($client))->of(GreetingWorkflow::class)
            ->typedSearchAttributes(TypedSearchAttributes::empty()->withValue($websiteId, 'site-1'));

        $options = $this->startAndCaptureOptions($pending, $client);

        self::assertSame('site-1', $options->typedSearchAttributes?->get($websiteId));
        self::assertNull($options->searchAttributes);
    }

    public function testStartOrSkipReturnsNullOnAlreadyStarted(): void
    {
        $alreadyStarted = (new \ReflectionClass(WorkflowExecutionAlreadyStartedException::class))->newInstanceWithoutConstructor();

        $client = $this->createStub(WorkflowClientInterface::class);
        $client->method('newWorkflowStub')->willReturn(new \stdClass());
        $client->method('start')->willThrowException($alreadyStarted);

        $launcher = new WorkflowLauncher($client);

        self::assertNull($launcher->of(GreetingWorkflow::class)->id('x')->startOrSkip('arg'));
    }
}
