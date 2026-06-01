#!/usr/bin/env bash
# Real-world validation of graceful worker error handling (docs/specs/graceful-error-handling.md).
# Builds a minimal Symfony app on top of this bundle, runs it under a real RoadRunner server in
# Docker, and asserts the client-visible behavior for catchable exceptions, die()/exit(), and prod.
#
# Usage: ./docker-validate-error-pages.sh
set -euo pipefail

IMAGE_TAG="rr-bundle-error-validation"
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
        "php": ">=8.5",
        "fluffydiscord/roadrunner-symfony-bundle": "*",
        "symfony/framework-bundle": "^7.4 || ^8",
        "symfony/runtime": "^7.4 || ^8",
        "symfony/yaml": "^7.4 || ^8"
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

    public function ok(): Response { return new Response('OK from worker pid=' . getmypid()); }
    public function boom(): Response { throw new \RuntimeException('boom: catchable exception from controller'); }
    public function exitAction(): Response { exit; }                                  // Bucket B: bare exit
    public function dieAction(): Response { die('DUMPED OUTPUT THAT POLLUTES STDOUT'); } // Bucket B: die w/ output

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'validation-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $r): void
    {
        $r->add('ok', '/ok')->controller([self::class, 'ok']);
        $r->add('boom', '/boom')->controller([self::class, 'boom']);
        $r->add('exit', '/exit')->controller([self::class, 'exitAction']);
        $r->add('die', '/die')->controller([self::class, 'dieAction']);
    }
}
PHP

cat > "$CTX/app/public/index.php" <<'PHP'
<?php
use App\Kernel;
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';
return fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
PHP

cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app
FAIL=0
assert() { # $1=label $2=haystack $3=needle
  if grep -qF -- "$3" <<< "$2"; then echo "  PASS: $1"; else echo "  FAIL: $1 (missing: $3)"; FAIL=1; fi  # here-string, not a pipe (avoids pipefail+SIGPIPE on large bodies)
}
gen_yaml() { # $1=debug(0|1)
  cat > /app/.rr.yaml <<YAML
version: "3"
server:
    command: "php public/index.php"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'
        APP_ENV: "${2:-dev}"
        APP_DEBUG: "$1"
        APP_SECRET: "validation-secret"
        RR_RPC: "tcp://127.0.0.1:6001"
http:
    address: "127.0.0.1:8080"
    pool:
        debug: true
rpc:
    listen: "tcp://127.0.0.1:6001"
logs:
    mode: production
YAML
}
run_rr() { ./rr serve -c /app/.rr.yaml &>/app/rr.log & echo $!; }
wait_ready() { curl -s -o /dev/null --retry 40 --retry-delay 1 --retry-connrefused --max-time 40 http://127.0.0.1:8080/ok; }
get() { curl -s --max-time 20 -o /tmp/b -w '%{http_code}' "http://127.0.0.1:8080/$1"; }

echo "### DEBUG mode (APP_DEBUG=1) ###"
gen_yaml 1 dev; RR=$(run_rr); wait_ready
code=$(get boom); body=$(cat /tmp/b); echo "/boom -> $code (${#body} body bytes)"
assert "boom is HTTP 500" "$code" "500"
assert "boom debug page shows the error detail" "$body" "boom: catchable exception from controller"
code=$(get exit);  body=$(cat /tmp/b); echo "/exit -> $code"; assert "exit is HTTP 500" "$code" "500"; assert "exit shows error page (not RR raw)" "$body" "Internal Server Error"
code=$(get die);   body=$(cat /tmp/b); echo "/die  -> $code"; assert "die is HTTP 500" "$code" "500"; assert "die shows error page" "$body" "Internal Server Error"
code=$(get ok);    body=$(cat /tmp/b); echo "/ok   -> $code"; assert "recovery: ok is 200" "$code" "200"; assert "recovery body" "$body" "OK from worker"
assert "STDERR log captured" "$(cat /app/rr.log)" "[roadrunner-symfony]"
kill "$RR" 2>/dev/null; wait "$RR" 2>/dev/null || true

echo "### PROD mode (APP_DEBUG=0) ###"
gen_yaml 0 prod; RR=$(run_rr); wait_ready
code=$(get exit); body=$(cat /tmp/b); echo "/exit -> $code (${#body} body bytes)"; assert "prod exit is HTTP 500" "$code" "500"
if [ -z "$body" ]; then echo "  PASS: prod exit body is empty (no info disclosure)"; else echo "  FAIL: prod exit leaked a body"; FAIL=1; fi
kill "$RR" 2>/dev/null; wait "$RR" 2>/dev/null || true

echo ""; [ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED ===" || { echo "=== SOME CHECKS FAILED ==="; }
exit "$FAIL"
BASH

cat > "$CTX/Dockerfile" <<'DOCKERFILE'
FROM php:8.5-cli-trixie
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
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

echo "=== Building image $IMAGE_TAG ==="
docker build -t "$IMAGE_TAG" "$CTX"

echo "=== Running validation ==="
docker run --rm "$IMAGE_TAG"
