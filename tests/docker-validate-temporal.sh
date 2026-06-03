#!/usr/bin/env bash
# Real end-to-end live validation of the Temporal.io integration (docs/specs/temporal-io-integration.md §6.2 IT-01..IT-03).
#
# Builds a minimal Symfony app on top of this bundle that defines a GreetingWorkflow + GreetingActivity
# (assigned to the "default" task queue via #[TaskQueue]), runs it inside a single Docker container
# against a REAL Temporal dev server (`temporal server start-dev`) driven by a REAL RoadRunner server with
# the `temporal` plugin, then starts the workflow from a client and asserts it returns exactly "Hello, World".
#
# Also proves the interceptor event pipeline (IT-03): a listener on the bundle's ActivityInbound ActivityEvent
# writes a marker file when the activity executes inside the worker; the harness asserts the marker is present.
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
    "repositories": [ { "type": "path", "url": "/bundle", "options": { "symlink": false } } ],
    "autoload": { "psr-4": { "App\\": "src/" } },
    "config": { "allow-plugins": { "symfony/runtime": true, "php-http/discovery": true } },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON

# --- Activity (interface + impl), assigned to the "default" task queue ---------------------------
cat > "$CTX/app/src/GreetingActivityInterface.php" <<'PHP'
<?php
namespace App;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'greeting.')]
interface GreetingActivityInterface
{
    #[ActivityMethod]
    public function greet(string $name): string;
}
PHP

cat > "$CTX/app/src/GreetingActivity.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;

// Activities MUST carry #[TaskQueue] or the bundle throws ActivityNotAssignedException
// at container-compile time. The attribute is TARGET_CLASS, so it lives on the impl class.
#[TaskQueue('default')]
class GreetingActivity implements GreetingActivityInterface
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
PHP

# --- Workflow (interface + impl), assigned to the "default" task queue ---------------------------
cat > "$CTX/app/src/GreetingWorkflowInterface.php" <<'PHP'
<?php
namespace App;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface GreetingWorkflowInterface
{
    #[WorkflowMethod(name: 'GreetingWorkflow')]
    public function greet(string $name): \Generator;
}
PHP

cat > "$CTX/app/src/GreetingWorkflow.php" <<'PHP'
<?php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
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

# --- Interceptor-event listener (IT-03): writes a marker when the activity runs in the worker -----
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
        @file_put_contents('/app/interceptor-marker', "fired\n", FILE_APPEND);
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

        // Register App\ classes as autowired/autoconfigured services so the bundle's
        // compile-time #[TaskQueue] scan sees the workflow + activity, and so the
        // #[AsEventListener] marker listener is wired into the dispatcher.
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

# --- Client: starts the workflow and prints the result -------------------------------------------
cat > "$CTX/app/client.php" <<'PHP'
<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\GreetingWorkflowInterface;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;

$serviceClient = ServiceClient::create('127.0.0.1:7233');
$workflowClient = WorkflowClient::create($serviceClient);

$workflow = $workflowClient->newWorkflowStub(
    GreetingWorkflowInterface::class,
    WorkflowOptions::new()
        ->withTaskQueue('default')
        ->withWorkflowExecutionTimeout(30),
);

$result = $workflow->greet('World');

echo $result . "\n";
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
FAIL=0

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

# Wait for the RR temporal worker to register with the dev server. We retry the client until the
# workflow can actually run (worker polling the "default" task queue), or we time out.
echo "### Running workflow client (assert 'Hello, World') ###"
RESULT=""
for i in $(seq 1 40); do
  if ! kill -0 "$RR_PID" 2>/dev/null; then echo "rr serve exited early"; dump_logs; exit 1; fi
  RESULT="$(php /app/client.php 2>/app/client.err)"
  if [ "$RESULT" = "Hello, World" ]; then break; fi
  sleep 1
done

echo "  client result: '${RESULT}'"
if [ -s /app/client.err ]; then echo "  (client stderr tail:)"; tail -n 5 /app/client.err; fi

if [ "$RESULT" = "Hello, World" ]; then
  echo "  PASS: workflow returned exactly 'Hello, World'"
else
  echo "  FAIL: workflow did not return 'Hello, World' (got: '${RESULT}')"; FAIL=1
fi

# IT-03: the interceptor-event listener must have fired during the real run.
if [ -s /app/interceptor-marker ]; then
  echo "  PASS: interceptor ActivityEvent listener fired ($(wc -l < /app/interceptor-marker | tr -d ' ') time(s))"
else
  echo "  FAIL: interceptor ActivityEvent listener never fired (IT-03)"; FAIL=1
fi

[ "$FAIL" -ne 0 ] && dump_logs

kill "$RR_PID" 2>/dev/null; wait "$RR_PID" 2>/dev/null || true
kill "$TEMPORAL_PID" 2>/dev/null; wait "$TEMPORAL_PID" 2>/dev/null || true

echo ""
[ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED (Hello, World) ===" || echo "=== SOME CHECKS FAILED ==="
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
