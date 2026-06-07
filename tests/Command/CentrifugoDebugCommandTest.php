<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Command;

use FluffyDiscord\RoadRunnerBundle\Command\CentrifugoDebugCommand;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;
use FluffyDiscord\RoadRunnerBundle\EventListener\CentrifugoEventRouter;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

class CentrifugoDebugCommandTest extends BaseTestCase
{
    public function testRendersChannelAndRpcRoutes(): void
    {
        $routingTable = [
            'channels' => [
                PublishEvent::class => [
                    'exact'    => ['news' => [['app.news_listener', 'onPublish', 0]]],
                    'wildcard' => [],
                ],
                SubscribeEvent::class => [
                    'exact'    => [],
                    'wildcard' => ['~^chat\:.*$~' => [['app.chat_listener', 'onSubscribe', 10]]],
                ],
            ],
            'rpc' => [
                'exact' => ['ping' => [['app.rpc_handler', 'onPing', 0]]],
            ],
        ];

        $command = new CentrifugoDebugCommand(new CentrifugoEventRouter(new ServiceLocator([]), $routingTable));
        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        self::assertStringContainsString('news', $output);
        self::assertStringContainsString('app.news_listener::onPublish', $output);
        self::assertStringContainsString('chat:*', $output);
        self::assertStringContainsString('app.chat_listener::onSubscribe', $output);
        self::assertStringContainsString('ping', $output);
        self::assertStringContainsString('app.rpc_handler::onPing', $output);
    }

    public function testRendersEmptyMessageWhenNoListeners(): void
    {
        $command = new CentrifugoDebugCommand(
            new CentrifugoEventRouter(new ServiceLocator([]), ['channels' => [], 'rpc' => ['exact' => []]]),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('No Centrifugo channel or RPC listeners are registered.', $tester->getDisplay());
    }
}
