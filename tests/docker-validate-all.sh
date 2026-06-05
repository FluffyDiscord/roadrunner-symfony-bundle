#!/usr/bin/env bash
# Comprehensive in-container validation: run the ENTIRE `phpunit tests` suite with nothing skipped
# because of the host. One Docker image provisions everything the suite can possibly need, so the
# host environment cannot dictate which tests run:
#
#   - PHP extensions ext-grpc + ext-igbinary installed (clears the grpc/igbinary skips);
#   - a real Temporal dev server (`temporal server start-dev`);
#   - a real RoadRunner server running http + jobs + temporal pools from ONE server.command;
#   - the gates exported: TEMPORAL_LIVE=1 and RR_JOBS_LIVE=1.
#
# The container's composer project IS the bundle itself (so autoload-dev exposes the committed
# tests/Temporal/Live/Workflow/* contracts), plus a small App\ worker layer that IMPLEMENTS those
# contracts and the #[AsJob] handlers. The same project then runs `php vendor/bin/phpunit tests`:
# the unit tests run normally, and the live tests act as the client against the running worker.
#
# The per-feature harnesses (docker-validate-temporal.sh / -jobs.sh / -error-pages.sh) remain for
# focused runs; this one proves the whole suite green in a single pass.
#
# Usage:
#   ./tests/docker-validate-all.sh            # all PHP versions in the list
#   ./tests/docker-validate-all.sh "8.4"      # only PHP 8.4
set -euo pipefail

# =============================================================================
# Config
# =============================================================================
PHP_VERSIONS=(8.4 8.5)
IMAGE_PREFIX="rr-bundle-all-validation"

[ "${1:-}" ] && read -ra PHP_VERSIONS <<< "$1"   # a single CLI arg narrows the version list
cd "$(dirname "$0")/.."                           # run from the repo root, wherever we're invoked from

# =============================================================================
# Helpers (same shape across every tests/docker-validate-*.sh)
# =============================================================================

# Copy a pre-fetched `rr` server binary into the context (env RR_BIN, or `rr` on PATH) to skip the
# GitHub download during the image build. No-op when none is available — the Dockerfile fetches it.
prefetch_rr() {
  local rr="${RR_BIN:-$(command -v rr 2>/dev/null || true)}"
  if [ -n "$rr" ] && [ -f "$rr" ]; then
    echo "Using pre-fetched rr binary: $rr"
    cp "$rr" "$CTX/app/rr"
  fi
}

# Build + run the image once per PHP version; aggregate failures into the exit code.
build_and_run() {
  local fail=0 tag php
  for php in "${PHP_VERSIONS[@]}"; do
    tag="${IMAGE_PREFIX}-php${php}"
    echo
    echo "=== Building image $tag (PHP ${php}) ==="
    if ! docker build --build-arg PHP_VERSION="$php" -t "$tag" "$CTX"; then
      echo "!!! BUILD FAILED: PHP ${php}"; fail=1; continue
    fi
    echo "=== Running full-suite validation (PHP ${php}) ==="
    docker run --rm "$tag" || fail=1
  done
  echo
  [ "$fail" -eq 0 ] && echo "=== ALL PHP VERSIONS PASSED ===" || echo "=== SOME PHP VERSIONS FAILED ==="
  return "$fail"
}

# =============================================================================
# 1. Build context — the whole bundle (incl. tests/, so autoload-dev exposes the
#    Workflow contracts) is the project root at /app; the worker layer overlays it
# =============================================================================
CTX="$(mktemp -d)"
trap 'rm -rf "$CTX"' EXIT

echo "=== Preparing build context in $CTX ==="
cp composer.json "$CTX/composer.json"
cp -r src "$CTX/src"
cp -r config "$CTX/config"
cp -r tests "$CTX/tests"
mkdir -p "$CTX/app/app-src" "$CTX/app/public" "$CTX/app/var"

# =============================================================================
# 2. Test app — an App\ worker layer that implements the bundle's Live\Workflow
#    contracts + the #[AsJob] handlers; added to the bundle's autoload-dev so one
#    composer project serves BOTH the RoadRunner worker and the PHPUnit client
# =============================================================================
cat > "$CTX/app/composer-patch.php" <<'PHP'
<?php
$file = __DIR__ . '/composer.json';
$json = json_decode((string) file_get_contents($file), true);
$json['autoload-dev']['psr-4']['App\\'] = 'app-src/';
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "patched autoload-dev with App\\\n";
PHP

# ---------------------------------------------------------------------------------------------------
# Temporal worker-side implementations of the bundle's committed Live\Workflow contracts.
# ---------------------------------------------------------------------------------------------------
cat > "$CTX/app/app-src/GreetingActivity.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\GreetingActivityInterface;

#[TaskQueue('default')]
class GreetingActivity implements GreetingActivityInterface
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
PHP

cat > "$CTX/app/app-src/GreetingWorkflow.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\GreetingActivityInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\GreetingWorkflowInterface;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;

#[TaskQueue('default')]
class GreetingWorkflow implements GreetingWorkflowInterface
{
    public function greet(string $name): \Generator
    {
        $activity = Workflow::newActivityStub(
            GreetingActivityInterface::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(10)
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1)),
        );

        return yield $activity->greet($name);
    }
}
PHP

cat > "$CTX/app/app-src/CounterWorkflow.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\CounterWorkflowInterface;
use Temporal\Workflow;

#[TaskQueue('default')]
class CounterWorkflow implements CounterWorkflowInterface
{
    private int $count = 0;
    private bool $done = false;

    public function run(): \Generator
    {
        yield Workflow::await(fn (): bool => $this->done);

        return $this->count;
    }

    public function add(int $n): void
    {
        $this->count += $n;
    }

    public function finish(): void
    {
        $this->done = true;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
PHP

cat > "$CTX/app/app-src/FailingActivity.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\FailingActivityInterface;

#[TaskQueue('default')]
class FailingActivity implements FailingActivityInterface
{
    public function boom(): string
    {
        throw new \RuntimeException('boom from activity');
    }
}
PHP

cat > "$CTX/app/app-src/FailingWorkflow.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\FailingActivityInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Live\Workflow\FailingWorkflowInterface;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;

#[TaskQueue('default')]
class FailingWorkflow implements FailingWorkflowInterface
{
    public function run(): \Generator
    {
        $activity = Workflow::newActivityStub(
            FailingActivityInterface::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(10)
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1)),
        );

        return yield $activity->boom();
    }
}
PHP

cat > "$CTX/app/app-src/ActivityMarkerListener.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class ActivityMarkerListener
{
    #[AsEventListener(event: ActivityEvent::class)]
    public function onActivity(ActivityEvent $event): void
    {
        $marker = getenv('TEMPORAL_INTERCEPTOR_MARKER') ?: '/app/var/interceptor-marker';
        @file_put_contents($marker, "fired\n", FILE_APPEND);
    }
}
PHP

# ---------------------------------------------------------------------------------------------------
# Jobs message-bus + queue-consumer fixtures.
# ---------------------------------------------------------------------------------------------------
cat > "$CTX/app/app-src/Ping.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

#[AsJob(queue: 'default')]
final class Ping
{
    public function __construct(public string $token) {}
}
PHP

cat > "$CTX/app/app-src/PingHandler.php" <<'PHP'
<?php
namespace App;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class PingHandler
{
    #[AsMessageHandler]
    public function __invoke(Ping $message): void
    {
        file_put_contents('/app/var/handled.json', json_encode([
            'token' => $message->token,
            'class' => get_class($message),
        ]));
    }
}
PHP

cat > "$CTX/app/app-src/CountPing.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

#[AsJob(queue: 'default')]
final class CountPing
{
    public function __construct(public string $token) {}
}
PHP

cat > "$CTX/app/app-src/CountPingHandler.php" <<'PHP'
<?php
namespace App;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CountPingHandler
{
    #[AsMessageHandler]
    public function __invoke(CountPing $message): void
    {
        $file = '/app/var/count-' . $message->token . '.txt';
        $count = is_file($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, (string) ($count + 1));
    }
}
PHP

cat > "$CTX/app/app-src/RawTaskListener.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class RawTaskListener
{
    #[AsEventListener(event: JobsRunEvent::class)]
    public function onJobsRun(JobsRunEvent $event): void
    {
        $headers = $event->getHeaders();
        if (isset($headers['x-job-class'])) {
            return; // enveloped task — leave it to the bundle's message bus.
        }

        file_put_contents('/app/var/raw-handled.json', json_encode([
            'name'    => $event->getName(),
            'payload' => $event->getPayload(),
        ]));
    }
}
PHP

cat > "$CTX/app/app-src/Kernel.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle;
use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;
use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use RoadRunnerMicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new FluffyDiscordRoadRunnerBundle()];
    }

    public function health(): Response { return new Response('OK pid=' . getmypid()); }

    public function dispatch(Request $request): Response
    {
        /** @var JobDispatcher $dispatcher */
        $dispatcher = $this->getContainer()->get(JobDispatcher::class);
        $dispatcher->dispatch(new Ping((string) $request->query->get('token', '')));

        return new Response('dispatched');
    }

    public function dispatchCount(Request $request): Response
    {
        /** @var JobDispatcher $dispatcher */
        $dispatcher = $this->getContainer()->get(JobDispatcher::class);
        $dispatcher->dispatch(new CountPing((string) $request->query->get('token', '')));

        return new Response('dispatched count');
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'validation-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        // Autoconfigure so #[TaskQueue] workflows/activities, #[AsMessageHandler] and #[AsEventListener]
        // are all picked up by the bundle's compile-time scans.
        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', '../app-src/');
    }

    protected function configureRoutes(RoutingConfigurator $r): void
    {
        $r->add('health', '/health')->controller([self::class, 'health']);
        $r->add('dispatch', '/dispatch')->controller([self::class, 'dispatch']);
        $r->add('dispatch_count', '/dispatch-count')->controller([self::class, 'dispatchCount']);
    }
}
PHP

cat > "$CTX/app/public/index.php" <<'PHP'
<?php
use App\Kernel;
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';
return fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
PHP

# =============================================================================
# 3. RoadRunner config (http + jobs + temporal in one server) + entrypoint —
#    provision Temporal + rr, then run the full `phpunit tests` with both gates on
# =============================================================================
cat > "$CTX/app/.rr.yaml" <<'YAML'
version: "3"
rpc:
    listen: "tcp://127.0.0.1:6001"
server:
    command: "php public/index.php"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'
        APP_ENV: "prod"
        APP_DEBUG: "0"
        APP_SECRET: "validation-secret"
        RR_RPC: "tcp://127.0.0.1:6001"
        TEMPORAL_INTERCEPTOR_MARKER: "/app/var/interceptor-marker"
http:
    address: "127.0.0.1:8080"
    pool:
        num_workers: 1
jobs:
    pool:
        num_workers: 1
    pipelines:
        default:
            driver: memory
            config:
                priority: 10
    consume: [ "default" ]
temporal:
    address: "127.0.0.1:7233"
    activities:
        num_workers: 1
logs:
    mode: production
    level: error
YAML

cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app
mkdir -p var
rm -f var/handled.json var/raw-handled.json var/interceptor-marker var/count-*.txt var/ju.xml

# Gates + endpoints the live tests read. NOTE: we deliberately do NOT export RR_RPC to the phpunit
# CLIENT process — a live RoadRunner listens on :6001 here, and the bundle's extension unit tests read
# RR_RPC during container builds; leaving it unset keeps them on their host-identical YAML-fallback
# path. The jobs-live raw push defaults to tcp://127.0.0.1:6001 on its own, and the rr WORKER
# processes get RR_RPC from .rr.yaml's server.env, so nothing real is lost.
export TEMPORAL_LIVE=1
export TEMPORAL_ADDRESS="127.0.0.1:7233"
export TEMPORAL_INTERCEPTOR_MARKER="/app/var/interceptor-marker"
export RR_JOBS_LIVE=1
export JOBS_VAR_DIR="/app/var"
export JOBS_HTTP_BASE="http://127.0.0.1:8080"

dump_logs() {
  echo "----- temporal server log -----"; tail -n 60 var/temporal.log 2>/dev/null || true
  echo "----- rr serve log -----";        tail -n 150 var/rr.log 2>/dev/null || true
}

echo "### Starting Temporal dev server (in-memory) ###"
temporal server start-dev --ip 0.0.0.0 --log-level error >var/temporal.log 2>&1 &
TEMPORAL_PID=$!
ready=0
for i in $(seq 1 60); do
  if (exec 3<>/dev/tcp/127.0.0.1/7233) 2>/dev/null; then exec 3>&- 3<&- ; ready=1; break; fi
  if ! kill -0 "$TEMPORAL_PID" 2>/dev/null; then echo "Temporal server exited early"; dump_logs; exit 1; fi
  sleep 1
done
[ "$ready" -eq 1 ] && echo "  Temporal frontend up on :7233" || { echo "  FAIL: Temporal frontend never came up"; dump_logs; exit 1; }

echo "### Starting RoadRunner (http + jobs + temporal pools) ###"
./rr serve -c /app/.rr.yaml >var/rr.log 2>&1 &
RR_PID=$!
if ! curl -s -o /dev/null --retry 40 --retry-delay 1 --retry-connrefused --max-time 40 http://127.0.0.1:8080/health; then
  echo "  FAIL: HTTP pool never became ready"; dump_logs; exit 1
fi
sleep 2
if ! kill -0 "$RR_PID" 2>/dev/null; then echo "  FAIL: rr serve exited early"; dump_logs; exit 1; fi
echo "  RoadRunner up (http :8080, jobs, temporal worker booting)"

echo "### Running the FULL suite in-container: php vendor/bin/phpunit tests ###"
FAIL=0
php vendor/bin/phpunit tests --log-junit var/ju.xml || FAIL=1

# The full run necessarily skips ONE test — testIgbinaryWithoutExtensionThrows asserts the
# MISSING-extension error path, which cannot be exercised while ext-igbinary is loaded. A process
# can't unload an extension at runtime, so run just that test in a SECOND process with the igbinary
# ini moved aside: function_exists('igbinary_serialize') is then false and the path executes.
echo ""
echo "### Supplementary: igbinary-absent path (ext-igbinary disabled for this one test) ###"
IGB_INI="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/docker-php-ext-igbinary.ini"
if [ -f "$IGB_INI" ]; then
  mv "$IGB_INI" "$IGB_INI.off"
  php vendor/bin/phpunit tests/Job/SerializerTest.php --filter testIgbinaryWithoutExtensionThrows --log-junit var/ju-igb.xml || FAIL=1
  mv "$IGB_INI.off" "$IGB_INI"
else
  echo "  WARN: igbinary ini not found at $IGB_INI; cannot exercise the extension-absent path"; FAIL=1
fi

echo ""
echo "### Skip report (net across the full run + the igbinary-absent run) ###"
php -r '
$skipped = static function (string $path): array {
    $x = @simplexml_load_file($path);
    if ($x === false) { return []; }
    $out = [];
    foreach ($x->xpath("//testcase[skipped]") as $tc) { $out[(string) $tc["class"] . "::" . (string) $tc["name"]] = true; }
    return $out;
};
$executed = static function (string $path): array {
    $x = @simplexml_load_file($path);
    if ($x === false) { return []; }
    $out = [];
    foreach ($x->xpath("//testcase") as $tc) { if (!count($tc->skipped)) { $out[(string) $tc["class"] . "::" . (string) $tc["name"]] = true; } }
    return $out;
};
$fullSkips = $skipped("var/ju.xml");
$recovered = array_intersect_key($fullSkips, $executed("var/ju-igb.xml"));
$net = array_diff_key($fullSkips, $recovered);
foreach (array_keys($recovered) as $name) { echo "  + $name — skipped in the full run, EXECUTED in the igbinary-absent run\n"; }
foreach (array_keys($net) as $name) { echo "  - $name — not executed by either run\n"; }
echo count($net) === 0
    ? "  net skipped: 0 — every test executed across the two runs\n"
    : "  net skipped: " . count($net) . "\n";
'

[ "$FAIL" -ne 0 ] && dump_logs

kill "$RR_PID" 2>/dev/null; wait "$RR_PID" 2>/dev/null || true
kill "$TEMPORAL_PID" 2>/dev/null; wait "$TEMPORAL_PID" 2>/dev/null || true

echo ""
[ "$FAIL" -eq 0 ] && echo "=== FULL SUITE PASSED IN DOCKER ===" || echo "=== SUITE HAD FAILURES ==="
exit "$FAIL"
BASH

# =============================================================================
# 4. Dockerfile — base + ext-grpc + ext-igbinary + Temporal CLI + the bundle/app
# =============================================================================
cat > "$CTX/Dockerfile" <<'DOCKERFILE'
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-trixie
# git/unzip/curl for composer + rr download; sockets for RR; ext-grpc for the Temporal client;
# ext-igbinary for the Jobs igbinary serializer tests.
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl binutils $PHPIZE_DEPS zlib1g-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets \
 # Refresh the pecl registry first — without it the build intermittently fails with
 # "No releases available for package pecl.php.net/grpc" on a stale/partial channel cache.
 && pecl channel-update pecl.php.net \
 # grpc's pecl build is single-threaded by default and embeds debug symbols, so it takes ~20 min
 # and yields a ~55 MB .so. pecl's make honors MAKEFLAGS, so parallelize it (capped at 8 to keep
 # grpc's memory-hungry C++ TUs from OOM-ing the box), then strip debug symbols (grpc/grpc#23626).
 && MAKEFLAGS="-j$(nproc | awk '{print ($1>8)?8:$1}')" pecl install igbinary grpc \
 && docker-php-ext-enable igbinary grpc \
 && strip --strip-debug "$(php-config --extension-dir)/grpc.so"
COPY --from=temporalio/temporal:latest /usr/local/bin/temporal /usr/local/bin/temporal
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json ./
COPY src/ src/
COPY config/ config/
COPY tests/ tests/
COPY app/ ./
RUN php composer-patch.php \
 && composer install --no-interaction --no-progress \
 && { [ -f /app/rr ] || php vendor/bin/rr get-binary --location /app; } \
 && chmod +x /app/rr /app/entrypoint.sh
CMD ["/app/entrypoint.sh"]
DOCKERFILE

# =============================================================================
# 5. Build & run
# =============================================================================
prefetch_rr
build_and_run
