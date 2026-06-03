<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Job\DependencyInjection\Compiler\JobHandlerPass;
use FluffyDiscord\RoadRunnerBundle\Job\EventListener\JobRoutingListener;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\MethodHandler;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\NoTypeHandler;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\PlainMessage;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmail;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmailHandler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Compiler-pass tests for the message-class → handler routing table. Mirrors CentrifugoRouterPassTest.
 * See docs/specs/jobs-message-bus.md §N-2 TC-08..TC-10.
 */
class JobHandlerPassTest extends BaseTestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new ContainerBuilder();
        $this->container->register(JobRoutingListener::class, JobRoutingListener::class)
            ->addArgument(null) // locator placeholder
            ->addArgument(null) // routing table placeholder
            ->addArgument([])   // serializer registry
        ;
    }

    private function runPass(): void
    {
        (new JobHandlerPass())->process($this->container);
    }

    /**
     * @return array<class-string, list<array{0: string, 1: string, 2: int}>>
     */
    private function routingTable(): array
    {
        /** @var array<class-string, list<array{0: string, 1: string, 2: int}>> $table */
        $table = $this->container->getDefinition(JobRoutingListener::class)->getArgument(1);

        return $table;
    }

    // TC-09
    public function testNoOpWhenListenerNotRegistered(): void
    {
        $container = new ContainerBuilder();
        (new JobHandlerPass())->process($container);

        self::assertFalse($container->hasDefinition(JobRoutingListener::class));
    }

    // TC-08: inferred message + explicit method/message
    public function testInvokableHandlerInfersMessageFromInvoke(): void
    {
        $this->container->register(SendWelcomeEmailHandler::class, SendWelcomeEmailHandler::class)
            ->addTag('fluffy_discord.job_handler', ['message' => null, 'priority' => 0, 'method' => null]);

        $this->runPass();

        $table = $this->routingTable();
        self::assertSame(
            [[SendWelcomeEmailHandler::class, '__invoke', 0]],
            $table[SendWelcomeEmail::class],
        );
    }

    public function testMethodHandlerWithExplicitMessageAndMethod(): void
    {
        $this->container->register(MethodHandler::class, MethodHandler::class)
            ->addTag('fluffy_discord.job_handler', ['message' => PlainMessage::class, 'priority' => 10, 'method' => 'handle']);

        $this->runPass();

        $table = $this->routingTable();
        self::assertSame(
            [[MethodHandler::class, 'handle', 10]],
            $table[PlainMessage::class],
        );
    }

    // TC-10
    public function testHandlersSortedByPriorityDescending(): void
    {
        $this->container->register(SendWelcomeEmailHandler::class, SendWelcomeEmailHandler::class)
            ->addTag('fluffy_discord.job_handler', ['message' => SendWelcomeEmail::class, 'priority' => 5, 'method' => '__invoke'])
            ->addTag('fluffy_discord.job_handler', ['message' => SendWelcomeEmail::class, 'priority' => 10, 'method' => '__invoke']);

        $this->runPass();

        $priorities = array_map(static fn (array $h): int => $h[2], $this->routingTable()[SendWelcomeEmail::class]);
        self::assertSame([10, 5], $priorities);
    }

    public function testThrowsWhenMethodMissing(): void
    {
        $this->container->register(SendWelcomeEmailHandler::class, SendWelcomeEmailHandler::class)
            ->addTag('fluffy_discord.job_handler', ['message' => SendWelcomeEmail::class, 'method' => 'nope']);

        $this->expectException(InvalidArgumentException::class);
        $this->runPass();
    }

    public function testThrowsWhenMessageCannotBeInferred(): void
    {
        // NoTypeHandler::__invoke has a `mixed` (non-class) parameter, so the inferred "message" is not
        // a loadable class and the pass must reject it.
        $this->container->register(NoTypeHandler::class, NoTypeHandler::class)
            ->addTag('fluffy_discord.job_handler', ['message' => null, 'method' => '__invoke']);

        $this->expectException(InvalidArgumentException::class);
        $this->runPass();
    }
}
