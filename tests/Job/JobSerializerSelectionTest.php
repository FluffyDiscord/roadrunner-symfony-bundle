<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\DependencyInjection\FluffyDiscordRoadRunnerExtension;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\IgbinaryJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\SymfonyJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The Extension repoints the JobSerializerInterface alias from jobs.serializer: an explicit
 * strategy wins, and the null default auto-selects igbinary when the extension is loaded, else
 * native.
 */
class JobSerializerSelectionTest extends BaseTestCase
{
    private function aliasTargetFor(?string $serializer): string
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        // temporal/sdk is installed in the test env, so load() always resolves the Temporal
        // frontend address; point it at the bundled .rr.yaml fixture so the build succeeds.
        $container->setParameter('kernel.project_dir', __DIR__ . '/../Temporal/Fixtures');

        (new FluffyDiscordRoadRunnerExtension())->load(
            [[
                'rr_config_path' => 'temporal.rr.yaml',
                'kv' => ['auto_register' => false],
                'jobs' => $serializer === null ? [] : ['serializer' => $serializer],
            ]],
            $container,
        );

        return (string) $container->getAlias(JobSerializerInterface::class);
    }

    public function testExplicitNativeIsSelected(): void
    {
        self::assertSame(NativeJobSerializer::class, $this->aliasTargetFor('native'));
    }

    public function testExplicitIgbinaryIsSelected(): void
    {
        self::assertSame(IgbinaryJobSerializer::class, $this->aliasTargetFor('igbinary'));
    }

    public function testExplicitSymfonyIsSelected(): void
    {
        self::assertSame(SymfonyJobSerializer::class, $this->aliasTargetFor('symfony'));
    }

    public function testDefaultAutoSelectsIgbinaryWhenAvailableElseNative(): void
    {
        $expected = function_exists('igbinary_serialize')
            ? IgbinaryJobSerializer::class
            : NativeJobSerializer::class;

        self::assertSame($expected, $this->aliasTargetFor(null));
    }
}
