#!/usr/bin/env bash
# Real end-to-end live validation of the Temporal.io integration (docs/specs/temporal-io-integration.md §6.2 IT-01..IT-05).
#
# Builds a minimal Symfony app on top of this bundle that IMPLEMENTS the bundle's committed
# Workflow\* live contracts (tests/Temporal/Live/Workflow/*) and assigns each impl to the "default"
# task queue via #[TaskQueue]. It runs inside a single Docker container against a REAL Temporal dev
# server (`temporal server start-dev`) driven by a REAL RoadRunner server with the `temporal` plugin.
#
# The assertions themselves live in the bundle's PHPUnit live test (tests/Temporal/Live/
# TemporalLiveTest.php, #[Group('temporal-live')]) — this harness only PROVISIONS the infrastructure
# and then runs `phpunit --group temporal-live` inside the container, so the PHPUnit live test IS the
# end-to-end check (single source of assertion logic):
#
#   Happy path:
#     IT-01/IT-02  GreetingWorkflow -> GreetingActivity returns exactly "Hello, World".
#     IT-03        the bundle's ActivityInbound interceptor event fires in the worker (a listener
#                  appends to the marker file the test then observes over the shared FS).
#     IT-04        CounterWorkflow started async, signalled twice (add 5, add 7), its getCount query
#                  reads back 12, then a finish signal lets the run complete.
#   Break path:
#     IT-05        FailingWorkflow calls an activity that always throws (maxAttempts=1); the client
#                  call MUST surface a WorkflowFailedException end-to-end, not a value or a hang.
#
# Runs against each PHP version below — edit PHP_VERSIONS or pass one as an arg:
#   ./tests/docker-validate-temporal.sh            # all versions
#   ./tests/docker-validate-temporal.sh "8.4"      # only PHP 8.4
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

mkdir -p "$CTX/app/src" "$CTX/app/public"

# The bundle's live test + its Workflow\* contracts + BaseTestCase run INSIDE the container as the
# assertion step; copy tests/ in and map the Tests\ namespace in the app's autoloader (below).
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
        "symfony/yaml": "^7.4 || ^8",
        "temporal/sdk": "^2.16"
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

# --- Worker-side implementations of the bundle's live contracts (each assigned to "default") ------
# Activities/workflows MUST carry #[TaskQueue] or the bundle throws Activity/WorkflowNotAssignedException
# at container-compile time; the attribute is TARGET_CLASS, so it lives on the impl class.
cat > "$CTX/app/src/GreetingActivity.php" <<'PHP'
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

cat > "$CTX/app/src/GreetingWorkflow.php" <<'PHP'
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

cat > "$CTX/app/src/CounterWorkflow.php" <<'PHP'
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

cat > "$CTX/app/src/FailingActivity.php" <<'PHP'
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

cat > "$CTX/app/src/FailingWorkflow.php" <<'PHP'
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
        // maxAttempts(1): the activity fails once and is NOT retried, so the failure
        // propagates to the client immediately instead of looping until the timeout.
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

# --- IT-03: a listener on the bundle's ActivityInbound event marks that it fired in the worker -----
cat > "$CTX/app/src/ActivityMarkerListener.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\ActivityInbound\ActivityEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class ActivityMarkerListener
{
    #[AsEventListener(event: ActivityEvent::class)]
    public function onActivity(ActivityEvent $event): void
    {
        $marker = getenv('TEMPORAL_INTERCEPTOR_MARKER') ?: '/app/interceptor-marker';
        @file_put_contents($marker, "fired\n", FILE_APPEND);
    }
}
PHP

cat > "$CTX/app/src/Kernel.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new FluffyDiscordRoadRunnerBundle()];
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'validation-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        // Register App\ classes as autowired/autoconfigured services so the bundle's compile-time
        // #[TaskQueue] scan sees the workflow + activity impls (which implement the bundle's
        // Workflow\* contracts), and so the #[AsEventListener] marker listener is wired.
        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', '../src/');
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
        TEMPORAL_INTERCEPTOR_MARKER: "/app/interceptor-marker"
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

# The PHPUnit live test reads these: the gate, the frontend address, and the interceptor marker file
# the worker's listener appends to (shared FS, asserted by IT-03).
export TEMPORAL_LIVE=1
export TEMPORAL_ADDRESS="127.0.0.1:7233"
export TEMPORAL_INTERCEPTOR_MARKER="/app/interceptor-marker"

dump_logs() {
  echo "----- temporal server log -----"; tail -n 60 /app/temporal.log 2>/dev/null || true
  echo "----- rr serve log -----";        tail -n 120 /app/rr.log 2>/dev/null || true
}

echo "### Starting Temporal dev server (in-memory) ###"
temporal server start-dev --ip 0.0.0.0 --log-level error >/app/temporal.log 2>&1 &
TEMPORAL_PID=$!

# Wait for the gRPC frontend on :7233 to accept connections.
ready=0
for i in $(seq 1 60); do
  if (exec 3<>/dev/tcp/127.0.0.1/7233) 2>/dev/null; then exec 3>&- 3<&- ; ready=1; break; fi
  if ! kill -0 "$TEMPORAL_PID" 2>/dev/null; then echo "Temporal server exited early"; dump_logs; exit 1; fi
  sleep 1
done
[ "$ready" -eq 1 ] && echo "  Temporal frontend up on :7233" || { echo "  FAIL: Temporal frontend never came up"; dump_logs; exit 1; }

echo "### Starting RoadRunner (temporal worker) ###"
./rr serve -c /app/.rr.yaml >/app/rr.log 2>&1 &
RR_PID=$!

# Give rr a moment to boot; fail fast if it died (config error), otherwise the live test's
# worker-readiness poll handles the registration delay.
sleep 2
if ! kill -0 "$RR_PID" 2>/dev/null; then echo "  FAIL: rr serve exited early"; dump_logs; exit 1; fi

echo "### Running the bundle live test suite (phpunit --group temporal-live) ###"
FAIL=0
php vendor/bin/phpunit bundle-tests/Temporal/Live --group temporal-live --testdox --colors=never || FAIL=1

[ "$FAIL" -ne 0 ] && dump_logs

kill "$RR_PID" 2>/dev/null; wait "$RR_PID" 2>/dev/null || true
kill "$TEMPORAL_PID" 2>/dev/null; wait "$TEMPORAL_PID" 2>/dev/null || true

echo ""
[ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED (temporal-live) ===" || echo "=== SOME CHECKS FAILED ==="
exit "$FAIL"
BASH

cat > "$CTX/Dockerfile" <<'DOCKERFILE'
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-trixie
# git/unzip/curl for composer + rr download; sockets for RR worker; ext-grpc is REQUIRED by the
# Temporal PHP client (Temporal\Client\GRPC\BaseClient throws unless extension_loaded('grpc')).
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl $PHPIZE_DEPS zlib1g-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets \
 && pecl install grpc \
 && docker-php-ext-enable grpc
# Temporal CLI (ships the in-memory dev server: `temporal server start-dev`).
COPY --from=temporalio/temporal:latest /usr/local/bin/temporal /usr/local/bin/temporal
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
  IMAGE_TAG="rr-bundle-temporal-validation-php${PHP_VERSION}"
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
