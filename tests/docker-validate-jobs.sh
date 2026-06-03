#!/usr/bin/env bash
# Real-world end-to-end validation of the RoadRunner Jobs message bus + queue-consumer worker
# (docs/specs/jobs-message-bus.md, docs/specs/rr-jobs-worker.md).
#
# Builds a minimal Symfony app on top of this bundle, runs it under a REAL RoadRunner server in Docker
# with BOTH an `http:` and a `jobs:` pool (memory driver) sharing ONE `server.command`. The assertions
# themselves live in the bundle's PHPUnit live tests (tests/Job/Live/*, #[Group('jobs-live')]); this
# harness only PROVISIONS the server and then runs `phpunit --group jobs-live` inside the container,
# so the PHPUnit live tests ARE the end-to-end check. They prove:
#   1. an HTTP request dispatches an #[AsJob] message; the jobs pool consumes the enveloped task, the
#      JobRoutingListener rehydrates it via the Native serializer, and the #[AsJobHandler] runs with a
#      real object carrying the exact token (observable at /app/var/handled.json);
#   2. a RAW, non-enveloped task pushed directly via the RR Jobs API still reaches a plain
#      JobsRunEvent listener (the bus is additive);
#   3. a successful task is consumed and acked EXACTLY ONCE (no erroneous redelivery).
#
# Runs against each PHP version below (official `php:<ver>-cli-trixie` images). Optionally narrow via arg:
#
# Usage:
#   ./tests/docker-validate-jobs.sh            # all PHP versions in the list
#   ./tests/docker-validate-jobs.sh "8.4"      # only PHP 8.4
set -euo pipefail

PHP_VERSIONS=(8.4 8.5)
[ "${1:-}" ] && read -ra PHP_VERSIONS <<< "$1"

# Build context is the repo root, regardless of where this script is invoked from.
cd "$(dirname "$0")/.."

CTX="$(mktemp -d)"
trap 'rm -rf "$CTX"' EXIT

echo "=== Preparing build context in $CTX ==="
cp composer.json "$CTX/composer.json"
cp -r src "$CTX/src"
cp -r config "$CTX/config"

mkdir -p "$CTX/app/src" "$CTX/app/public" "$CTX/app/var"

# The bundle's live tests + BaseTestCase run INSIDE the container as the assertion step; copy tests/
# in and map the Tests\ namespace in the app's autoloader (below).
cp -r tests "$CTX/app/bundle-tests"

# Provide a pre-fetched rr server binary if available (env RR_BIN, or `rr` on PATH) to avoid a
# GitHub download during the build; otherwise the Dockerfile fetches it via `rr get-binary`.
RR_BIN="${RR_BIN:-$(command -v rr 2>/dev/null || true)}"
if [ -n "${RR_BIN:-}" ] && [ -f "$RR_BIN" ]; then echo "Using pre-fetched rr binary: $RR_BIN"; cp "$RR_BIN" "$CTX/app/rr"; fi

cat > "$CTX/app/composer.json" <<'JSON'
{
    "require": {
        "php": ">=8.4",
        "fluffydiscord/roadrunner-symfony-bundle": "*",
        "symfony/framework-bundle": "^7.4 || ^8",
        "symfony/runtime": "^7.4 || ^8",
        "symfony/yaml": "^7.4 || ^8"
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

# --- Job message: a plain PHP object marked #[AsJob] with a public scalar payload. ------------------
cat > "$CTX/app/src/Ping.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

#[AsJob(queue: 'default')]
final class Ping
{
    public function __construct(public string $token) {}
}
PHP

# --- Handler: writes observable proof (token + real rehydrated class) to /app/var/handled.json. -----
cat > "$CTX/app/src/PingHandler.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJobHandler;

final class PingHandler
{
    #[AsJobHandler]
    public function __invoke(Ping $message): void
    {
        file_put_contents('/app/var/handled.json', json_encode([
            'token' => $message->token,
            'class' => get_class($message),
        ]));
    }
}
PHP

# --- Ack-once message + handler: counts deliveries per token so a redelivery is observable. ----------
cat > "$CTX/app/src/CountPing.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

#[AsJob(queue: 'default')]
final class CountPing
{
    public function __construct(public string $token) {}
}
PHP

cat > "$CTX/app/src/CountPingHandler.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJobHandler;

final class CountPingHandler
{
    #[AsJobHandler]
    public function __invoke(CountPing $message): void
    {
        $file = '/app/var/count-' . $message->token . '.txt';
        $count = is_file($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, (string) ($count + 1));
    }
}
PHP

# --- Raw JobsRunEvent listener (bonus): handles a NON-enveloped task pushed via the raw RR Jobs API. -
cat > "$CTX/app/src/RawTaskListener.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class RawTaskListener
{
    // Default priority (0) runs BEFORE the bundle's JobRoutingListener (priority -100). A raw task
    // carries no x-job-class header, so the routing listener ignores it — this listener owns it.
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

cat > "$CTX/app/src/Kernel.php" <<'PHP'
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
        $token = (string) $request->query->get('token', '');
        /** @var JobDispatcher $dispatcher */
        $dispatcher = $this->getContainer()->get(JobDispatcher::class);
        $dispatcher->dispatch(new Ping($token));

        return new Response('dispatched token=' . $token);
    }

    public function dispatchCount(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');
        /** @var JobDispatcher $dispatcher */
        $dispatcher = $this->getContainer()->get(JobDispatcher::class);
        $dispatcher->dispatch(new CountPing($token));

        return new Response('dispatched count token=' . $token);
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'validation-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        // Register App services with autoconfigure so #[AsJobHandler] / #[AsEventListener] are wired.
        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->set(PingHandler::class);
        $services->set(CountPingHandler::class);
        $services->set(RawTaskListener::class);
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
logs:
    mode: production
    encoding: console
    level: debug
YAML

cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app

rm -f /app/var/handled.json /app/var/raw-handled.json /app/var/count-*.txt

# The PHPUnit live tests read these: the gate, the proof dir, the HTTP base, and the RR RPC endpoint.
export RR_JOBS_LIVE=1
export JOBS_VAR_DIR="/app/var"
export JOBS_HTTP_BASE="http://127.0.0.1:8080"
export RR_RPC="tcp://127.0.0.1:6001"

echo "### Starting RoadRunner (http + jobs pools, one server.command) ###"
./rr serve -c /app/.rr.yaml &>/app/rr.log &
RR=$!

# Wait until the HTTP pool is serving (the live tests drive dispatch over HTTP).
if ! curl -s -o /dev/null --retry 40 --retry-delay 1 --retry-connrefused --max-time 40 http://127.0.0.1:8080/health; then
  echo "  FAIL: HTTP pool never became ready"; tail -n 80 /app/rr.log; exit 1
fi

echo "### Running the bundle live test suite (phpunit --group jobs-live) ###"
FAIL=0
php vendor/bin/phpunit bundle-tests/Job/Live --group jobs-live --testdox --colors=never || FAIL=1

[ "$FAIL" -ne 0 ] && { echo ""; echo "----- rr.log (last 80 lines) -----"; tail -n 80 /app/rr.log; }

kill "$RR" 2>/dev/null; wait "$RR" 2>/dev/null || true

echo ""; [ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED (jobs-live) ===" || echo "=== SOME CHECKS FAILED ==="
exit "$FAIL"
BASH

cat > "$CTX/Dockerfile" <<'DOCKERFILE'
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli-trixie
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets
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

FAIL=0
for PHP_VERSION in "${PHP_VERSIONS[@]}"; do
  IMAGE_TAG="rr-bundle-jobs-validation-php${PHP_VERSION}"
  echo ""
  echo "=== Building image $IMAGE_TAG (PHP ${PHP_VERSION}) ==="
  if ! docker build --build-arg PHP_VERSION="${PHP_VERSION}" -t "$IMAGE_TAG" "$CTX"; then
    echo "!!! BUILD FAILED: PHP ${PHP_VERSION}"; FAIL=1; continue
  fi
  echo "=== Running validation (PHP ${PHP_VERSION}) ==="
  docker run --rm "$IMAGE_TAG" || FAIL=1
done

echo ""
[ "$FAIL" -eq 0 ] && echo "=== ALL PHP VERSIONS PASSED ===" || echo "=== SOME PHP VERSIONS FAILED ==="
exit "$FAIL"
