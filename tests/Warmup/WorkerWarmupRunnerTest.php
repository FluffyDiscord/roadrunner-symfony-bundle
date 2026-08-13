<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Warmup;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Warmup\WorkerWarmerInterface;
use FluffyDiscord\RoadRunnerBundle\Warmup\WorkerWarmupRunner;
use FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker;
use Psr\Log\AbstractLogger;

/** See docs/specs/worker-warmup.md §9 (U1-U3b). */
class WorkerWarmupRunnerTest extends BaseTestCase
{
    /**
     * @return array{0: AbstractLogger, 1: array<int, array{0: string, 1: string}>&\ArrayAccess<int, array{0: string, 1: string}>}
     */
    private function makeCollectingLogger(): array
    {
        $records = new \ArrayObject();
        $logger = new class($records) extends AbstractLogger {
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records->append([(string) $level, (string) $message]);
            }
        };

        return [$logger, $records];
    }

    public function testRunsWarmersInIterationOrder(): void
    {
        $calls = [];
        $makeWarmer = function (string $name) use (&$calls): WorkerWarmerInterface {
            return new class($name, $calls) implements WorkerWarmerInterface {
                /** @param list<string> $calls */
                public function __construct(private readonly string $name, private array &$calls)
                {
                }

                public function warmup(): void
                {
                    $this->calls[] = $this->name;
                }
            };
        };

        $runner = new WorkerWarmupRunner([$makeWarmer('first'), $makeWarmer('second'), $makeWarmer('third')]);
        $runner(new WorkerBootingEvent());

        self::assertSame(['first', 'second', 'third'], $calls);
    }

    public function testSwallowsWarmerFailureAndContinues(): void
    {
        $calls = [];
        $failing = new class implements WorkerWarmerInterface {
            public function warmup(): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $succeeding = new class($calls) implements WorkerWarmerInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function warmup(): void
            {
                $this->calls[] = 'ran';
            }
        };

        [$logger, $records] = $this->makeCollectingLogger();
        $runner = new WorkerWarmupRunner([$failing, $succeeding], $logger);
        $runner(new WorkerBootingEvent());

        self::assertSame(['ran'], $calls);
        $errorRecords = array_filter((array) $records, static fn(array $record) => $record[0] === 'error');
        self::assertCount(1, $errorRecords);
    }

    public function testSetsBootWarmupFlagDuringRunAndResetsOnThrow(): void
    {
        $observedFlag = null;
        $observer = new class($observedFlag) implements WorkerWarmerInterface {
            public function __construct(private ?bool &$observedFlag)
            {
            }

            public function warmup(): void
            {
                $this->observedFlag = HttpWorker::$bootWarmupInProgress;

                throw new \RuntimeException('still must reset the flag');
            }
        };

        $runner = new WorkerWarmupRunner([$observer]);
        $runner(new WorkerBootingEvent());

        self::assertTrue($observedFlag, 'flag must be set while warmers run');
        self::assertFalse(HttpWorker::$bootWarmupInProgress, 'flag must be reset after the run');
    }

    public function testSurvivesThrowDuringIteratorAdvancement(): void
    {
        $calls = [];
        $firstWarmer = new class($calls) implements WorkerWarmerInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function warmup(): void
            {
                $this->calls[] = 'first';
            }
        };

        // Tagged iterators instantiate services lazily while the loop advances; a failing
        // constructor surfaces at the foreach statement, not inside warmup().
        $throwingIterator = (function () use ($firstWarmer): \Generator {
            yield $firstWarmer;

            throw new \RuntimeException('constructor exploded during advancement');
        })();

        [$logger, $records] = $this->makeCollectingLogger();
        $runner = new WorkerWarmupRunner($throwingIterator, $logger);

        $runner(new WorkerBootingEvent());

        self::assertSame(['first'], $calls, 'warmers before the failure must have run');
        self::assertFalse(HttpWorker::$bootWarmupInProgress, 'flag must be reset');
        $errorRecords = array_filter((array) $records, static fn(array $record) => $record[0] === 'error');
        self::assertCount(1, $errorRecords, 'the aborted advancement must be logged, never thrown');
    }

    public function testSwallowsStdoutFromWarmers(): void
    {
        $echoing = new class implements WorkerWarmerInterface {
            public function warmup(): void
            {
                echo 'protocol-corrupting bytes';
            }
        };

        [$logger, $records] = $this->makeCollectingLogger();
        $runner = new WorkerWarmupRunner([$echoing], $logger);

        ob_start();
        $runner(new WorkerBootingEvent());
        $leaked = ob_get_clean();

        self::assertSame('', $leaked, 'nothing may reach stdout');
        $warningRecords = array_filter((array) $records, static fn(array $record) => $record[0] === 'warning');
        self::assertCount(1, $warningRecords);
    }
}
