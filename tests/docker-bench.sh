#!/usr/bin/env bash
#
# RPS + conversion-attribution benchmark harness.
#
# Builds a minimal Symfony bench app on top of this bundle, runs it under a real RoadRunner
# server in Docker, and measures three servers with wrk on loopback inside the container:
#   S1 raw            — bare Spiral HttpWorker loop (no Symfony): the ceiling
#   S2 bundle-psr7    — the bundle with request_factory: psr7 (current conversion chain)
#   S3 bundle-native  — the bundle with request_factory: native (optimized path)
# Before the request_factory config node exists in the bundle, S2 runs as the unmodified
# app ("legacy") and S3 is skipped — that is the spec's step-1 baseline mode.
#
# Also runs the µs-resolution attribution bench (tests/bench/conversion-bench.php) and the
# Centrifugo events/sec driver (tests/bench/centrifugo-bench.php) in the same image.
#
# Usage:
#   ./tests/docker-bench.sh                # Symfony 7.4 and 8.1, PHP 8.4
#   ./tests/docker-bench.sh "7.4"          # one Symfony version
#   ./tests/docker-bench.sh "7.4 8.1" 8.5  # both, PHP 8.5
#
# Tunables (env): BENCH_CPUS=2 BENCH_CONNECTIONS=16 BENCH_DURATION=15s
#                 BENCH_WARMUP_DURATION=5s BENCH_ITERATIONS=20000 BENCH_CENTRIFUGO_EVENTS=10000
#
set -euo pipefail

# =============================================================================
# Config
# =============================================================================
SYMFONY_VERSIONS=(7.4 8.1)
PHP_VERSION="8.4"
IMAGE_PREFIX="rr-bundle-bench"

[ "${1:-}" ] && read -ra SYMFONY_VERSIONS <<< "$1"
[ "${2:-}" ] && PHP_VERSION="$2"
cd "$(dirname "$0")/.."

# =============================================================================
# Helpers
# =============================================================================
prefetch_rr() {
  local rr="${RR_BIN:-$(command -v rr 2>/dev/null || true)}"
  if [ -n "$rr" ] && [ -f "$rr" ]; then
    echo "Using pre-fetched rr binary: $rr"
    cp "$rr" "$CTX/app/rr"
  fi
}

build_and_run() {
  local fail=0 tag sf
  for sf in "${SYMFONY_VERSIONS[@]}"; do
    tag="${IMAGE_PREFIX}-php${PHP_VERSION}-sf${sf}"
    echo
    echo "=== Building bench image: PHP ${PHP_VERSION} / Symfony ${sf} ==="
    if ! docker build --build-arg PHP_VERSION="$PHP_VERSION" --build-arg SYMFONY_VERSION="$sf" -t "$tag" "$CTX"; then
      echo "!!! BUILD FAILED: Symfony ${sf}"; fail=1; continue
    fi
    echo "=== Benchmarking: PHP ${PHP_VERSION} / Symfony ${sf} (cpus=${BENCH_CPUS:-2}) ==="
    docker run --rm --cpus="${BENCH_CPUS:-2}" \
      -e BENCH_CONNECTIONS -e BENCH_DURATION -e BENCH_WARMUP_DURATION \
      -e BENCH_ITERATIONS -e BENCH_CENTRIFUGO_EVENTS \
      "$tag" || fail=1
  done
  echo
  [ "$fail" -eq 0 ] && echo "=== ALL BENCH RUNS COMPLETED ===" || echo "=== SOME BENCH RUNS FAILED ==="
  return "$fail"
}

# =============================================================================
# 1. Build context — the bundle to /bundle (path repo), the bench app to /app
# =============================================================================
CTX="$(mktemp -d)"
trap 'rm -rf "$CTX"' EXIT

echo "=== Preparing build context in $CTX ==="
cp composer.json "$CTX/composer.json"
cp -r src "$CTX/src"
cp -r config "$CTX/config"
mkdir -p "$CTX/app/src" "$CTX/app/public" "$CTX/app/bench"
cp tests/bench/conversion-bench.php tests/bench/centrifugo-bench.php "$CTX/app/bench/"

# =============================================================================
# 2. Bench app — micro-kernel with constant-payload routes
# =============================================================================
cat > "$CTX/app/composer.json" <<'JSON'
{
    "require": {
        "php": ">=8.4",
        "fluffydiscord/roadrunner-symfony-bundle": "*",
        "symfony/framework-bundle": "^7.4 || ^8",
        "symfony/runtime": "^7.4 || ^8",
        "symfony/yaml": "^7.4 || ^8",
        "roadrunner-php/centrifugo": "^2"
    },
    "repositories": [ { "type": "path", "url": "/bundle", "options": { "symlink": false } } ],
    "autoload": { "psr-4": { "App\\": "src/" } },
    "config": { "allow-plugins": { "symfony/runtime": true, "php-http/discovery": true } },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON

cat > "$CTX/app/src/Kernel.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle;
use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    public function bench(): Response
    {
        return new Response('ok');
    }

    public function benchJson(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    public function benchInfo(): Response
    {
        $resolvedParameterName = 'fluffy_discord.http.request_factory.resolved';

        $parameterExists = $this->container->hasParameter($resolvedParameterName);
        if (!$parameterExists) {
            return new Response('legacy-chain');
        }

        return new Response((string) $this->container->getParameter($resolvedParameterName));
    }

    public function benchUpload(Request $request): Response
    {
        $uploadedFile = $request->files->get('f');
        if ($uploadedFile === null) {
            return new Response('no-file', Response::HTTP_BAD_REQUEST);
        }

        $movedFile = $uploadedFile->move(sys_get_temp_dir() . '/bench-moved', uniqid('u', true) . '.bin');

        return new Response('moved:' . $movedFile->getSize());
    }

    protected function configureContainer(ContainerConfigurator $containerConfigurator): void
    {
        $containerConfigurator->extension('framework', [
            'secret' => 'bench-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $requestFactoryChoice = $_SERVER['BENCH_REQUEST_FACTORY'] ?? '';
        if ($requestFactoryChoice !== '') {
            $containerConfigurator->extension('fluffy_discord_road_runner', [
                'http' => ['request_factory' => $requestFactoryChoice],
            ]);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routingConfigurator): void
    {
        $routingConfigurator->add('bench', '/bench')->controller([self::class, 'bench']);
        $routingConfigurator->add('bench_json', '/bench-json')->controller([self::class, 'benchJson']);
        $routingConfigurator->add('bench_info', '/bench-info')->controller([self::class, 'benchInfo']);
        $routingConfigurator->add('bench_upload', '/bench-upload')->controller([self::class, 'benchUpload'])->methods(['POST']);
    }
}
PHP

cat > "$CTX/app/public/index.php" <<'PHP'
<?php
use App\Kernel;
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';
return fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
PHP

cat > "$CTX/app/public/worker-raw.php" <<'PHP'
<?php
use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Worker;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$httpWorker = new HttpWorker(Worker::create());

while (true) {
    $request = $httpWorker->waitRequest();
    if ($request === null) {
        break;
    }

    $httpWorker->respond(200, 'ok', ['Content-Type' => ['text/plain']]);
}
PHP

# =============================================================================
# 3. Entrypoint — per-server: readiness gate, strategy assertion, warmup + 3 wrk runs
# =============================================================================
cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app
FAIL=0
BENCH_CONNECTIONS="${BENCH_CONNECTIONS:-16}"
BENCH_DURATION="${BENCH_DURATION:-15s}"
BENCH_WARMUP_DURATION="${BENCH_WARMUP_DURATION:-5s}"
MEDIANS_FILE=/tmp/bench-medians
: > "$MEDIANS_FILE"

gen_yaml() { # $1=server command  $2=BENCH_REQUEST_FACTORY value ('' = node unset)
  cat > /app/.rr.yaml <<YAML
version: "3"
server:
    command: "$1"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'
        APP_ENV: "prod"
        APP_DEBUG: "0"
        APP_SECRET: "bench-secret"
        BENCH_REQUEST_FACTORY: "$2"
        RR_RPC: "tcp://127.0.0.1:6001"
http:
    address: "127.0.0.1:8080"
    pool:
        num_workers: 1
rpc:
    listen: "tcp://127.0.0.1:6001"
logs:
    mode: production
    level: error
YAML
}

start_rr() { ./rr serve -c /app/.rr.yaml &>/app/rr.log & RR_PID=$!; }
stop_rr() { kill "$RR_PID" 2>/dev/null; wait "$RR_PID" 2>/dev/null || true; }

wait_ready() { # -> readiness body of /bench
  curl -s --retry 40 --retry-delay 1 --retry-connrefused --max-time 40 http://127.0.0.1:8080/bench
}

wrk_runs() { # $1=label $2=url — 1 discarded warmup + 3 timed runs, min/median/max
  local label="$1" url="$2" out rps p50 p99 runs=() sorted
  wrk -t1 -c"$BENCH_CONNECTIONS" -d"$BENCH_WARMUP_DURATION" "$url" >/dev/null 2>&1
  for i in 1 2 3; do
    out=$(wrk -t1 -c"$BENCH_CONNECTIONS" -d"$BENCH_DURATION" --latency "$url")
    rps=$(awk '/Requests\/sec:/ {print $2}' <<< "$out")
    p50=$(awk '$1=="50%" {print $2}' <<< "$out")
    p99=$(awk '$1=="99%" {print $2}' <<< "$out")
    if [ -z "$rps" ]; then echo "  FAIL: wrk output unparseable for $label"; echo "$out"; FAIL=1; return 1; fi
    echo "  run$i: ${rps} req/s (p50 ${p50}, p99 ${p99})"
    runs+=("$rps")
  done
  sorted=$(printf '%s\n' "${runs[@]}" | sort -n)
  local min med max
  min=$(sed -n 1p <<< "$sorted"); med=$(sed -n 2p <<< "$sorted"); max=$(sed -n 3p <<< "$sorted")
  echo "  ${label}: min=${min} median=${med} max=${max} req/s"
  echo "${label} ${med}" >> "$MEDIANS_FILE"
}

upload_smoke() { # $1=label — D2 assumption check: app can move the RR-written upload
  printf 'hello' > /tmp/upload-src.bin
  local body
  body=$(curl -s --max-time 10 -F "f=@/tmp/upload-src.bin" http://127.0.0.1:8080/bench-upload)
  if [ "$body" = "moved:5" ]; then echo "  PASS: upload smoke ($1)"; else echo "  FAIL: upload smoke ($1) got: $body"; FAIL=1; fi
}

bench_server() { # $1=label $2=server command $3=factory value $4=expected /bench-info substring ('' = skip) $5=assert-only ('' = full timing)
  echo
  echo "--- ${1} ---"
  rm -rf /app/var/cache
  gen_yaml "$2" "$3"
  start_rr
  local body info
  body=$(wait_ready)
  if [ "$body" != "ok" ]; then
    echo "  FAIL: $1 not ready (body: $body)"; tail -20 /app/rr.log; FAIL=1; stop_rr; return 1
  fi
  if [ -n "$4" ]; then
    info=$(curl -s --max-time 10 http://127.0.0.1:8080/bench-info)
    case "$info" in
      *"$4"*) echo "  strategy: $info" ;;
      *) echo "  FAIL: $1 expected strategy matching '$4', got: $info (stale compiled container? cache was cleared)"; FAIL=1; stop_rr; return 1 ;;
    esac
  fi
  if [ -n "${5:-}" ]; then
    echo "  PASS: $1 (assert-only, not timed)"
    stop_rr
    return 0
  fi
  local is_symfony_server=0
  [ "$2" != "php public/worker-raw.php" ] && is_symfony_server=1
  if [ "$is_symfony_server" = 1 ]; then upload_smoke "$1"; fi
  wrk_runs "${1}:/bench" "http://127.0.0.1:8080/bench"
  if [ "$is_symfony_server" = 1 ]; then wrk_runs "${1}:/bench-json" "http://127.0.0.1:8080/bench-json"; fi
  stop_rr
}

echo "### environment ###"
php -v | head -1
php -r 'printf("opcache.enable_cli=%s validate_timestamps=%s jit=%s jit_buffer_size=%s\n", ini_get("opcache.enable_cli"), ini_get("opcache.validate_timestamps"), ini_get("opcache.jit") ?: "off", ini_get("opcache.jit_buffer_size"));'
composer show symfony/http-kernel 2>/dev/null | awk '/^versions/ {print "symfony/http-kernel " $NF}'
./rr --version 2>/dev/null | head -1
echo "wrk: -t1 -c${BENCH_CONNECTIONS} -d${BENCH_DURATION} (+ ${BENCH_WARMUP_DURATION} discarded warmup), pool num_workers=1"

HAS_FACTORY_NODE=0
grep -q "request_factory" /bundle/src/DependencyInjection/Configuration.php 2>/dev/null && HAS_FACTORY_NODE=1

bench_server "S1-raw" "php public/worker-raw.php" "" ""

if [ "$HAS_FACTORY_NODE" = 1 ]; then
  bench_server "S2-bundle-psr7" "php public/index.php" "psr7" "Psr7"
  bench_server "S3-bundle-native" "php public/index.php" "native" "Native"
  bench_server "S4-bundle-auto-default" "php public/index.php" "" "Native" "assert-only"
else
  echo
  echo "(request_factory node not present in bundle - step-1 baseline mode: S2 = legacy chain, S3 skipped)"
  bench_server "S2-bundle-legacy" "php public/index.php" "" "legacy-chain"
fi

echo
echo "### median ratio table ###"
awk '
  { medians[$1] = $2 }
  END {
    s1 = medians["S1-raw:/bench"]
    for (label in medians) printf "%-28s %10.2f req/s\n", label, medians[label]
    s2 = medians["S2-bundle-psr7:/bench"]; if (s2 == "") s2 = medians["S2-bundle-legacy:/bench"]
    s3 = medians["S3-bundle-native:/bench"]
    if (s1 > 0 && s2 > 0) printf "S2/S1 = %.3f\n", s2 / s1
    if (s1 > 0 && s3 > 0) printf "S3/S1 = %.3f\n", s3 / s1
    if (s2 > 0 && s3 > 0) printf "S3/S2 = %.3f  (claim a wrk delta only if min-max ranges above do not overlap)\n", s3 / s2
  }
' "$MEDIANS_FILE"

echo
echo "### conversion attribution (the actual proof for sub-5% effects) ###"
php bench/conversion-bench.php || FAIL=1

echo
echo "### centrifugo driver ###"
php bench/centrifugo-bench.php || FAIL=1

echo
[ "$FAIL" -eq 0 ] && echo "=== BENCH SESSION COMPLETE ===" || echo "=== BENCH SESSION HAD FAILURES ==="
exit "$FAIL"
BASH

# =============================================================================
# 4. Dockerfile
# =============================================================================
cat > "$CTX/Dockerfile" <<'DOCKERFILE'
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-trixie

ARG SYMFONY_VERSION

RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl wrk \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets \
 && docker-php-ext-enable opcache \
 && { \
      echo "opcache.enable_cli=1"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.jit=off"; \
      echo "opcache.jit_buffer_size=0"; \
    } > /usr/local/etc/php/conf.d/bench.ini
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json /bundle/composer.json
COPY src/ /bundle/src/
COPY config/ /bundle/config/
WORKDIR /app
COPY app/ /app/
RUN composer config minimum-stability dev \
 && composer config prefer-stable true \
 && composer require --no-update --no-interaction \
      "symfony/dependency-injection:^${SYMFONY_VERSION}" \
      "symfony/http-kernel:^${SYMFONY_VERSION}" \
      "symfony/http-foundation:^${SYMFONY_VERSION}" \
      "symfony/psr-http-message-bridge:^${SYMFONY_VERSION}" \
      "symfony/event-dispatcher:^${SYMFONY_VERSION}" \
      "symfony/framework-bundle:^${SYMFONY_VERSION}" \
      "symfony/runtime:^${SYMFONY_VERSION}" \
      "symfony/yaml:^${SYMFONY_VERSION}" \
 && composer update --prefer-dist --no-interaction --no-progress \
 && composer show symfony/http-kernel | awk -v want="${SYMFONY_VERSION}" '/^versions/ { if (index($NF, "v" want ".") != 1) { print "FATAL: symfony/http-kernel " $NF " does not match requested " want; exit 1 } print "pinned symfony/http-kernel " $NF }' \
 && { [ -f /app/rr ] || php vendor/bin/rr get-binary --location /app; } \
 && chmod +x /app/rr /app/entrypoint.sh
CMD ["/app/entrypoint.sh"]
DOCKERFILE

# =============================================================================
# 5. Build & run
# =============================================================================
prefetch_rr
build_and_run
