<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\GlobalState;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\Request as RoadRunnerRequest;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

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

$iterations = (int) (getenv('BENCH_ITERATIONS') ?: 20000);
$warmupIterations = intdiv($iterations, 10);

parse_str('x=1&y[]=2&y[]=3&z=abc', $queryParameters);

$rrRequest = new RoadRunnerRequest(
    remoteAddr: '10.0.0.5',
    protocol: 'HTTP/1.1',
    method: 'GET',
    uri: 'http://localhost:8080/bench/path?x=1&y[]=2&y[]=3&z=abc',
    headers: [
        'Host'             => ['localhost:8080'],
        'User-Agent'       => ['bench/1.0'],
        'Accept'           => ['text/html,application/xhtml+xml'],
        'Accept-Language'  => ['en-US,en;q=0.9'],
        'Accept-Encoding'  => ['gzip, deflate'],
        'Connection'       => ['keep-alive'],
        'Cache-Control'    => ['no-cache'],
        'X-Requested-With' => ['XMLHttpRequest'],
        'X-Forwarded-For'  => ['203.0.113.9'],
        'Cookie'           => ['session=abc123; theme=dark'],
    ],
    cookies: ['session' => 'abc123', 'theme' => 'dark'],
    uploads: [],
    attributes: [RoadRunnerRequest::PARSED_BODY_ATTRIBUTE_NAME => false],
    query: $queryParameters,
    body: '',
    parsed: false,
);

$fakeGoridgeWorker = new class implements WorkerInterface {
    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function respond(Payload $payload): void
    {
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
};

$psr17Factory = new Psr17Factory();
$psr7Worker = new PSR7Worker($fakeGoridgeWorker, $psr17Factory, $psr17Factory, $psr17Factory);

$mapRequest = Closure::bind(
    fn (RoadRunnerRequest $request, array $server) => $this->mapRequest($request, $server),
    $psr7Worker,
    PSR7Worker::class,
);

$bridgeFactory = new HttpFoundationFactory();

$measure = function (string $label, callable $operation) use ($iterations, $warmupIterations): float {
    for ($i = 0; $i < $warmupIterations; $i++) {
        $operation();
    }

    $startNanoseconds = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $operation();
    }
    $microsecondsPerRequest = (hrtime(true) - $startNanoseconds) / 1000 / $iterations;

    printf("%-52s %8.2f us/request\n", $label, $microsecondsPerRequest);

    return $microsecondsPerRequest;
};

printf(
    "conversion attribution: PHP %s, %d iterations, opcache.enable_cli=%s\n\n",
    PHP_VERSION,
    $iterations,
    ini_get('opcache.enable_cli') ?: '0',
);

$server = GlobalState::enrichServerVars($rrRequest);
$psrRequest = $mapRequest($rrRequest, $server);

$measure('GlobalState::enrichServerVars', fn () => GlobalState::enrichServerVars($rrRequest));
$measure('legacy PSR7Worker::mapRequest', fn () => $mapRequest($rrRequest, $server));
$measure('legacy bridge HttpFoundationFactory::createRequest', fn () => $bridgeFactory->createRequest($psrRequest));
$measure('legacy chain total (enrich + map + bridge)', function () use ($rrRequest, $mapRequest, $bridgeFactory): void {
    $enrichedServer = GlobalState::enrichServerVars($rrRequest);
    $mappedPsrRequest = $mapRequest($rrRequest, $enrichedServer);
    $bridgeFactory->createRequest($mappedPsrRequest);
});

$serverParamsFactoryClass = 'FluffyDiscord\RoadRunnerBundle\Factory\ServerParamsFactory';
$serverParamsFactory = class_exists($serverParamsFactoryClass) ? new $serverParamsFactoryClass() : null;

if ($serverParamsFactory !== null) {
    $measure('ServerParamsFactory::createServerParams', fn () => $serverParamsFactory->createServerParams($rrRequest));
}

$psr7StrategyClass = 'FluffyDiscord\RoadRunnerBundle\Factory\Psr7SymfonyRequestFactory';
$nativeStrategyClass = 'FluffyDiscord\RoadRunnerBundle\Factory\NativeSymfonyRequestFactory';

$psr7StrategyAvailable = class_exists($psr7StrategyClass) && $serverParamsFactory !== null;
if ($psr7StrategyAvailable) {
    $psr7Strategy = new $psr7StrategyClass();
    $measure('Psr7SymfonyRequestFactory (strategy, incl. server params)', function () use ($rrRequest, $psr7Strategy, $serverParamsFactory): void {
        $serverParams = $serverParamsFactory->createServerParams($rrRequest);
        $psr7Strategy->createRequest($rrRequest, $serverParams);
    });
}

$nativeStrategyAvailable = class_exists($nativeStrategyClass) && $serverParamsFactory !== null;
if ($nativeStrategyAvailable) {
    $nativeStrategy = new $nativeStrategyClass();
    $measure('NativeSymfonyRequestFactory (strategy, incl. server params)', function () use ($rrRequest, $nativeStrategy, $serverParamsFactory): void {
        $serverParams = $serverParamsFactory->createServerParams($rrRequest);
        $nativeStrategy->createRequest($rrRequest, $serverParams);
    });
}

$strategiesMissing = !$psr7StrategyAvailable && !$nativeStrategyAvailable;
if ($strategiesMissing) {
    echo "\nstrategy classes not present - legacy-chain baseline only\n";
}
