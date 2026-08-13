<?php

use FluffyDiscord\RoadRunnerBundle\Worker\CentrifugoWorker;
use RoadRunner\Centrifugo\CentrifugoWorker as RoadRunnerCentrifugoWorker;
use RoadRunner\Centrifugo\Request;
use RoadRunner\Centrifugo\Request\RequestFactory;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    '/app/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadCandidate) {
    $autoloadExists = is_file($autoloadCandidate);
    if ($autoloadExists) {
        require_once $autoloadCandidate;
        break;
    }
}

$eventsPerType = (int) (getenv('BENCH_CENTRIFUGO_EVENTS') ?: 10000);

class CountingGoridgeWorker implements WorkerInterface
{
    public int $respondedFrames = 0;

    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function respond(Payload $payload): void
    {
        ++$this->respondedFrames;
    }

    public function error(string $error): void
    {
    }

    public function stop(): void
    {
    }

    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    public function getPayload(?string $class = null): ?Payload
    {
        return null;
    }
}

class BenchKernel implements KernelInterface
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function boot(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): BundleInterface
    {
        throw new LogicException('no bundles in bench kernel');
    }

    public function locateResource(string $name): string
    {
        throw new LogicException('no resources in bench kernel');
    }

    public function getEnvironment(): string
    {
        return 'prod';
    }

    public function isDebug(): bool
    {
        return false;
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getStartTime(): float
    {
        return 0.0;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getBuildDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getShareDir(): string
    {
        return sys_get_temp_dir();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function handle(HttpRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        throw new LogicException('bench kernel does not handle HTTP');
    }
}

class BenchCentrifugoWorker extends CentrifugoWorker
{
    /** @var list<Request\RequestInterface> */
    public array $requestQueue = [];

    private int $queuePosition = 0;

    public function resetQueue(): void
    {
        $this->queuePosition = 0;
    }

    protected function waitRequest(): ?Request\RequestInterface
    {
        $request = $this->requestQueue[$this->queuePosition] ?? null;
        ++$this->queuePosition;

        return $request;
    }

    protected function registerShutdown(callable $handler): void
    {
    }

    protected function logError(string $message): void
    {
        fwrite(STDERR, 'bench worker error: ' . $message . "\n");
    }
}

$countingWorker = new CountingGoridgeWorker();
$roadRunnerCentrifugoWorker = new RoadRunnerCentrifugoWorker($countingWorker, new RequestFactory($countingWorker));

$fixtureBuilders = [
    'connect' => fn (): Request\Connect => new Request\Connect($countingWorker, 'client', 'transport', 'json', 'json', [], null, null, [], []),
    'publish' => fn (): Request\Publish => new Request\Publish($countingWorker, 'client', 'transport', 'json', 'json', 'user', 'channel', [], [], []),
    'rpc'     => fn (): Request\RPC => new Request\RPC($countingWorker, 'client', 'transport', 'json', 'json', 'user', 'method', [], [], []),
];

printf("centrifugo driver: PHP %s, %d events/type, 1 discarded + 3 timed runs\n\n", PHP_VERSION, $eventsPerType);

$exitCode = 0;

foreach ($fixtureBuilders as $typeLabel => $fixtureBuilder) {
    $queue = [];
    for ($i = 0; $i < $eventsPerType; $i++) {
        $queue[] = $fixtureBuilder();
    }

    $eventsPerSecondRuns = [];

    for ($run = 0; $run < 4; $run++) {
        $worker = new BenchCentrifugoWorker(
            lazyBoot: false,
            debug: false,
            kernel: new BenchKernel(),
            worker: $roadRunnerCentrifugoWorker,
            eventDispatcher: new EventDispatcher(),
            servicesResetter: null,
        );
        $worker->requestQueue = $queue;
        $worker->resetQueue();

        $framesBefore = $countingWorker->respondedFrames;
        $startNanoseconds = hrtime(true);
        $worker->start();
        $elapsedSeconds = (hrtime(true) - $startNanoseconds) / 1e9;
        $framesDelivered = $countingWorker->respondedFrames - $framesBefore;

        if ($framesDelivered !== $eventsPerType) {
            fwrite(STDERR, sprintf("FAIL: %s run %d delivered %d frames, expected %d\n", $typeLabel, $run, $framesDelivered, $eventsPerType));
            $exitCode = 1;
            continue;
        }

        $isDiscardedWarmupRun = $run === 0;
        if ($isDiscardedWarmupRun) {
            continue;
        }

        $eventsPerSecondRuns[] = $eventsPerType / $elapsedSeconds;
    }

    sort($eventsPerSecondRuns);
    $runCount = count($eventsPerSecondRuns);

    if ($runCount === 3) {
        printf(
            "%-8s min=%.0f median=%.0f max=%.0f events/sec\n",
            $typeLabel,
            $eventsPerSecondRuns[0],
            $eventsPerSecondRuns[1],
            $eventsPerSecondRuns[2],
        );
    }
}

exit($exitCode);
