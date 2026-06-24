#!/usr/bin/env bash
# Real end-to-end live validation of the Doctrine PostgreSQL boot-time preconnect.
#
# Builds a minimal Symfony app on top of this bundle with doctrine-bundle and TWO DBAL
# connections — `default` (PostgreSQL) and `secondary` (sqlite) — inside a single Docker
# container running a REAL PostgreSQL server and a REAL RoadRunner HTTP worker.
#
# The assertion lives in the bundle's PHPUnit live test (tests/Doctrine/Live/
# DoctrinePreconnectLiveTest.php, #[Group('doctrine-preconnect-live')]); this harness only
# PROVISIONS the infrastructure and then runs `phpunit --group doctrine-preconnect-live`
# inside the container, so the PHPUnit live test IS the end-to-end check:
#
#   /db-status runs NO query, only reports each connection's isConnected() + driver. So:
#     - default (PostgreSQL) MUST report connected=true  -> the boot-time listener opened the
#       socket before the first request (lazy connections are otherwise closed at boot).
#     - secondary (sqlite)   MUST report connected=false -> the listener skips non-PG drivers
#       (it must NOT connect or warn for them).
#
# Runs against each PHP version below — edit PHP_VERSIONS or pass one as an arg:
#   ./tests/docker-validate-doctrine-preconnect.sh           # all versions
#   ./tests/docker-validate-doctrine-preconnect.sh "8.4"     # only PHP 8.4
set -euo pipefail

# =============================================================================
# Config
# =============================================================================
PHP_VERSIONS=(8.4 8.5)
IMAGE_PREFIX="rr-bundle-doctrine-preconnect-validation"

[ "${1:-}" ] && read -ra PHP_VERSIONS <<< "$1"   # a single CLI arg narrows the version list
cd "$(dirname "$0")/.."                           # run from the repo root, wherever we're invoked from

# =============================================================================
# Helpers (same shape across every tests/docker-validate-*.sh)
# =============================================================================
prefetch_rr() {
  local rr="${RR_BIN:-$(command -v rr 2>/dev/null || true)}"
  if [ -n "$rr" ] && [ -f "$rr" ]; then
    echo "Using pre-fetched rr binary: $rr"
    cp "$rr" "$CTX/app/rr"
  fi
}

build_and_run() {
  local fail=0 tag php
  for php in "${PHP_VERSIONS[@]}"; do
    tag="${IMAGE_PREFIX}-php${php}"
    echo
    echo "=== Building image $tag (PHP ${php}) ==="
    if ! docker build --build-arg PHP_VERSION="$php" -t "$tag" "$CTX"; then
      echo "!!! BUILD FAILED: PHP ${php}"; fail=1; continue
    fi
    echo "=== Running validation (PHP ${php}) ==="
    docker run --rm "$tag" || fail=1
  done
  echo
  [ "$fail" -eq 0 ] && echo "=== ALL PHP VERSIONS PASSED ===" || echo "=== SOME PHP VERSIONS FAILED ==="
  return "$fail"
}

# =============================================================================
# 1. Build context — bundle -> /bundle (path repo); the app + the bundle's live
#    test (the PHPUnit assertions) -> /app
# =============================================================================
CTX="$(mktemp -d)"
trap 'rm -rf "$CTX"' EXIT

echo "=== Preparing build context in $CTX ==="
cp composer.json "$CTX/composer.json"
cp -r src "$CTX/src"
cp -r config "$CTX/config"
mkdir -p "$CTX/app/src" "$CTX/app/public"

# The live test + BaseTestCase run INSIDE the container as the assertion step.
cp -r tests "$CTX/app/bundle-tests"

# =============================================================================
# 2. Test app — doctrine-bundle with a PostgreSQL default + sqlite secondary,
#    and a /db-status route that reports isConnected() without querying
# =============================================================================
cat > "$CTX/app/composer.json" <<'JSON'
{
    "require": {
        "php": ">=8.4",
        "fluffydiscord/roadrunner-symfony-bundle": "*",
        "symfony/framework-bundle": "^7.4",
        "symfony/runtime": "^7.4",
        "symfony/yaml": "^7.4",
        "doctrine/doctrine-bundle": "^2.13 || ^3",
        "doctrine/dbal": "^4"
    },
    "require-dev": {
        "phpunit/phpunit": "^13"
    },
    "repositories": [ { "type": "path", "url": "/bundle", "options": { "symlink": false } } ],
    "autoload": {
        "psr-4": {
            "App\\": "src/",
            "FluffyDiscord\\RoadRunnerBundle\\Tests\\": "bundle-tests/"
        }
    },
    "config": { "allow-plugins": { "symfony/runtime": true, "php-http/discovery": true } },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON

cat > "$CTX/app/src/Kernel.php" <<'PHP'
<?php
namespace App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new FluffyDiscordRoadRunnerBundle()];
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'validation-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $c->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections' => [
                    'default'   => ['url' => '%env(resolve:DATABASE_URL)%'],
                    'secondary' => ['url' => '%env(resolve:SECONDARY_DATABASE_URL)%'],
                ],
            ],
        ]);

        $c->extension('fluffy_discord_road_runner', ['doctrine' => ['preconnect' => true]]);

        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', '../src/');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('health', '/health')->controller(HealthController::class);
        $routes->add('db_status', '/db-status')->controller(DbStatusController::class);
    }
}
PHP

cat > "$CTX/app/src/HealthController.php" <<'PHP'
<?php
namespace App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

// Readiness probe — deliberately touches no database, so polling it cannot open a connection.
class HealthController extends AbstractController
{
    public function __invoke(): JsonResponse
    {
        return $this->json(['ok' => true]);
    }
}
PHP

cat > "$CTX/app/src/DbStatusController.php" <<'PHP'
<?php
namespace App;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;

// Reports each connection's live socket state WITHOUT querying. A "connected" PostgreSQL
// socket here can only have come from the boot-time preconnect listener.
class DbStatusController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')] private Connection $default,
        #[Autowire(service: 'doctrine.dbal.secondary_connection')] private Connection $secondary,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        // Report the configured driver NAME (params['driver']) — the same signal the listener
        // uses. getDriver()::class would be a middleware wrapper under doctrine-bundle.
        return $this->json([
            'default'   => ['connected' => $this->default->isConnected(),   'driver' => $this->default->getParams()['driver'] ?? null],
            'secondary' => ['connected' => $this->secondary->isConnected(), 'driver' => $this->secondary->getParams()['driver'] ?? null],
        ]);
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
# 3. RoadRunner config + entrypoint — provision PostgreSQL, start the rr worker,
#    then run `phpunit --group doctrine-preconnect-live` (the live test asserts)
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
        DATABASE_URL: "postgresql://app:app@127.0.0.1:5432/app?charset=utf8"
        SECONDARY_DATABASE_URL: "pdo-sqlite:///:memory:"
http:
    address: "0.0.0.0:8080"
    pool:
        # One worker keeps the assertion deterministic: the worker that boots + preconnects
        # is the one that serves /db-status.
        num_workers: 1
logs:
    mode: production
    level: error
YAML

cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app

export DOCTRINE_PRECONNECT_LIVE=1
export APP_BASE_URL="http://127.0.0.1:8080"

dump_logs() {
  echo "----- postgres log -----"; tail -n 40 /var/log/postgresql/*.log 2>/dev/null || true
  echo "----- rr serve log -----"; tail -n 120 /app/rr.log 2>/dev/null || true
}

PG_VER="$(ls /etc/postgresql | head -1)"
echo "### Starting PostgreSQL ${PG_VER} ###"
pg_ctlcluster "$PG_VER" main start

ready=0
for i in $(seq 1 30); do
  if pg_isready -h 127.0.0.1 -p 5432 -q; then ready=1; break; fi
  sleep 1
done
[ "$ready" -eq 1 ] || { echo "  FAIL: PostgreSQL never became ready"; dump_logs; exit 1; }

echo "### Provisioning role + database ###"
su postgres -c "psql -v ON_ERROR_STOP=1 -c \"CREATE ROLE app LOGIN PASSWORD 'app';\" -c \"CREATE DATABASE app OWNER app;\"" \
  || { echo "  FAIL: could not provision the app role/database"; dump_logs; exit 1; }

echo "### Starting RoadRunner (http worker, 1 worker, eager boot) ###"
./rr serve -c /app/.rr.yaml >/app/rr.log 2>&1 &
RR_PID=$!

# Readiness: /health touches no DB, so polling it cannot open a connection.
if ! curl -s -o /dev/null --retry 40 --retry-delay 1 --retry-connrefused --max-time 40 "$APP_BASE_URL/health"; then
  echo "  FAIL: HTTP pool never became ready"; dump_logs; kill "$RR_PID" 2>/dev/null; exit 1
fi
if ! kill -0 "$RR_PID" 2>/dev/null; then echo "  FAIL: rr serve exited early"; dump_logs; exit 1; fi

echo "### Running the bundle live test (phpunit --group doctrine-preconnect-live) ###"
FAIL=0
php vendor/bin/phpunit bundle-tests/Doctrine/Live --group doctrine-preconnect-live --testdox --colors=never || FAIL=1

[ "$FAIL" -ne 0 ] && dump_logs

kill "$RR_PID" 2>/dev/null; wait "$RR_PID" 2>/dev/null || true
pg_ctlcluster "$PG_VER" main stop 2>/dev/null || true

echo ""
[ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED (doctrine-preconnect-live) ===" || echo "=== SOME CHECKS FAILED ==="
exit "$FAIL"
BASH

# =============================================================================
# 4. Dockerfile — base + pdo_pgsql (PG driver) + a PostgreSQL server + rr binary
# =============================================================================
cat > "$CTX/Dockerfile" <<'DOCKERFILE'
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-trixie
# git/unzip/curl for composer + rr download; sockets for the RR worker; libpq-dev to build
# pdo_pgsql; postgresql is the in-container database the preconnect actually opens.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl libpq-dev postgresql \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json /bundle/composer.json
COPY src/ /bundle/src/
COPY config/ /bundle/config/
WORKDIR /app
COPY app/ /app/
RUN composer install --no-interaction --no-progress \
 && { [ -f /app/rr ] || php vendor/bin/rr get-binary --location /app; } \
 && chmod +x /app/rr /app/entrypoint.sh
CMD ["/app/entrypoint.sh"]
DOCKERFILE

# =============================================================================
# 5. Build & run
# =============================================================================
prefetch_rr
build_and_run
