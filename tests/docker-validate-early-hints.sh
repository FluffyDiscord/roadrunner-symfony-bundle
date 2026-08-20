#!/usr/bin/env bash
#
# Real-world validation that 103 Early Hints headers are not repeated in the final response.
#
# RoadRunner's Go handler writes worker headers with w.Header().Add(), and net/http deliberately
# keeps 1xx headers in handlerHeader (RFC 8297). Re-sending the whole header bag with the final
# frame therefore puts every early-hint header on the wire twice. Asserted here against a real
# RoadRunner server behind a real nginx reverse proxy.
#
# Usage:
#   ./tests/docker-validate-early-hints.sh            # every PHP version in PHP_VERSIONS
#   ./tests/docker-validate-early-hints.sh "8.4"      # only PHP 8.4
#
set -euo pipefail

# =============================================================================
# Config
# =============================================================================
PHP_VERSIONS=(8.4 8.5)
IMAGE_PREFIX="rr-bundle-early-hints-validation"

[ "${1:-}" ] && read -ra PHP_VERSIONS <<< "$1"
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
# 1. Build context
# =============================================================================
CTX="$(mktemp -d)"
trap 'rm -rf "$CTX"' EXIT

echo "=== Preparing build context in $CTX ==="
cp composer.json "$CTX/composer.json"
cp -r src "$CTX/src"
cp -r config "$CTX/config"
mkdir -p "$CTX/app/src" "$CTX/app/public"

# =============================================================================
# 2. Test app
# =============================================================================
cat > "$CTX/app/composer.json" <<'JSON'
{
    "require": {
        "php": ">=8.4",
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

    public function ok(): Response { return new Response('OK'); }

    public function noHints(): Response { return new Response('no hints here'); }

    public function hintsSameResponse(): Response
    {
        $response = new Response('hinted body');
        $response->headers->set('Link', '</style.css>; rel="preload"');
        $response->sendHeaders(103);

        return $response;
    }

    public function hintsChangedHeader(): Response
    {
        $response = new Response('changed body');
        $response->headers->set('Link', '</style.css>; rel="preload"');
        $response->sendHeaders(103);
        $response->headers->set('Link', '</changed.css>; rel="preload"');

        return $response;
    }

    public function duplicateContentLength(): Response
    {
        $body = 'duplicated';

        $response = new Response($body);
        $response->headers->set('Content-Length', (string)\strlen($body));
        $response->headers->set('X-Duplicate-Content-Length', '1');

        return $response;
    }

    public function hintsRepeated(): Response
    {
        $response = new Response('twice hinted');
        $response->headers->set('Link', '</style.css>; rel="preload"');
        $response->sendHeaders(103);
        $response->sendHeaders(103);

        return $response;
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'validation-secret', 'test' => false,
            'http_method_override' => false, 'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $c->services()->set(\App\DuplicateContentLengthListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.response', 'priority' => -100]);
    }

    protected function configureRoutes(RoutingConfigurator $r): void
    {
        $r->add('ok', '/ok')->controller([self::class, 'ok']);
        $r->add('no_hints', '/no-hints')->controller([self::class, 'noHints']);
        $r->add('hints_same', '/hints-same')->controller([self::class, 'hintsSameResponse']);
        $r->add('hints_changed', '/hints-changed')->controller([self::class, 'hintsChangedHeader']);
        $r->add('hints_repeated', '/hints-repeated')->controller([self::class, 'hintsRepeated']);
        $r->add('duplicate_cl', '/duplicate-content-length')->controller([self::class, 'duplicateContentLength']);
    }
}
PHP

cat > "$CTX/app/src/DuplicateContentLengthListener.php" <<'PHP'
<?php
namespace App;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

class DuplicateContentLengthListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response->headers->has('X-Duplicate-Content-Length')) {
            return;
        }

        $response->headers->set('Content-Length', '999', false);
    }
}
PHP

cat > "$CTX/app/public/index.php" <<'PHP'
<?php
use App\Kernel;
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';
return fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
PHP

cat > "$CTX/app/nginx.conf" <<'NGINX'
worker_processes 1;
error_log /app/nginx-error.log warn;
pid /tmp/nginx.pid;
events { worker_connections 64; }
http {
    access_log off;
    client_body_temp_path /tmp/nginx-body;
    proxy_temp_path /tmp/nginx-proxy;
    fastcgi_temp_path /tmp/nginx-fcgi;
    uwsgi_temp_path /tmp/nginx-uwsgi;
    scgi_temp_path /tmp/nginx-scgi;
    server {
        listen 127.0.0.1:8081;
        location / { proxy_pass http://127.0.0.1:8080; proxy_http_version 1.1; }
    }
}
NGINX

# =============================================================================
# 3. Entrypoint
# =============================================================================
cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app
FAIL=0

cat > /app/.rr.yaml <<'YAML'
version: "3"
server:
    command: "php public/index.php"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'
        APP_ENV: "dev"
        APP_DEBUG: "1"
        APP_SECRET: "validation-secret"
        RR_RPC: "tcp://127.0.0.1:6001"
http:
    address: "127.0.0.1:8080"
    pool:
        num_workers: 1
rpc:
    listen: "tcp://127.0.0.1:6001"
logs:
    mode: production
YAML

./rr serve -c /app/.rr.yaml &>/app/rr.log &
RR=$!
nginx -c /app/nginx.conf
curl -s -o /dev/null --retry 40 --retry-delay 1 --retry-connrefused --max-time 40 http://127.0.0.1:8080/ok || true

# Everything after the final status line: the headers the client actually keeps.
final_block() { sed -n '/^HTTP\/1.1 200/,$p' "$1"; }

count_header() { # $1=headers-file $2=header name
  final_block "$1" | grep -ci "^$2:" || true
}

assert_count() { # $1=label $2=actual $3=expected
  if [ "$2" -eq "$3" ]; then echo "  PASS: $1 (=$3)"; else echo "  FAIL: $1 (expected $3, got $2)"; FAIL=1; fi
}

assert_contains() { # $1=label $2=haystack $3=needle
  if grep -qF -- "$3" <<< "$2"; then echo "  PASS: $1"; else echo "  FAIL: $1 (missing: $3)"; FAIL=1; fi
}

probe() { # $1=port $2=path
  curl -sS -D /tmp/h -o /tmp/b --max-time 25 "http://127.0.0.1:$1$2" >/dev/null
}

assert_clean_log_so_far() {
  local unexpected
  unexpected=$(cat /app/nginx-error.log 2>/dev/null || true)
  if [ -n "$unexpected" ]; then echo "  FAIL: nginx logged errors during the early-hints probes"; echo "$unexpected"; FAIL=1; fi
}

for PORT in 8080 8081; do
  [ "$PORT" = 8080 ] && WHERE="direct RoadRunner" || WHERE="through nginx"
  echo "### $WHERE (:$PORT) ###"

  probe "$PORT" /hints-same
  echo "-- /hints-same"
  assert_contains "103 frame carries the Link hint" "$(cat /tmp/h)" '</style.css>; rel="preload"'
  assert_count "final response has one Link"          "$(count_header /tmp/h Link)" 1
  assert_count "final response has one Date"          "$(count_header /tmp/h Date)" 1
  assert_count "final response has one Cache-Control" "$(count_header /tmp/h Cache-Control)" 1
  assert_contains "body intact" "$(cat /tmp/b)" "hinted body"

  probe "$PORT" /hints-changed
  echo "-- /hints-changed"
  assert_contains "changed Link value reaches the client" "$(final_block /tmp/h)" '</changed.css>; rel="preload"'
  assert_count "documented limit: the stale 103 Link is stranded on the wire" "$(count_header /tmp/h Link)" 2
  assert_count "final response has one Date" "$(count_header /tmp/h Date)" 1

  probe "$PORT" /hints-repeated
  echo "-- /hints-repeated"
  assert_count "two identical 103 sends still leave one Link" "$(count_header /tmp/h Link)" 1
  assert_count "final response has one Date"                  "$(count_header /tmp/h Date)" 1

  # Same persistent worker as the hinted requests above: 1xx bookkeeping must not leak forward.
  probe "$PORT" /no-hints
  echo "-- /no-hints (same worker, right after hinted requests)"
  assert_count "no stale Link leaks into a later request" "$(count_header /tmp/h Link)" 0
  assert_count "later request still has its Date"         "$(count_header /tmp/h Date)" 1
  assert_contains "body intact" "$(cat /tmp/b)" "no hints here"

  assert_clean_log_so_far
done

# This response is deliberately malformed: it advertises two different Content-Length values, so
# curl waits for a body that never arrives. Only the headers matter here — the short timeout is
# the point, not a failure.
echo "### duplicate Content-Length (app-level defect, parity with PHP-FPM) ###"
echo "-- direct RoadRunner (curl timeouts below are the malformed response, and are expected)"
assert_count "RoadRunner emits both Content-Length values" \
  "$(curl -s -D - -o /dev/null --max-time 3 http://127.0.0.1:8080/duplicate-content-length | grep -ci '^content-length:')" 2
DUP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:8081/duplicate-content-length)
assert_contains "nginx rejects it with 502" "$DUP_CODE" "502"
assert_contains "nginx says duplicate header line" "$(cat /app/nginx-error.log)" "duplicate header line"
assert_contains "worker warned about it on STDERR" "$(cat /app/rr.log)" "nginx rejects a duplicated Content-Length"

echo "### nginx error log ###"
UNEXPECTED=$(grep -v 'duplicate header line' /app/nginx-error.log 2>/dev/null || true)
if [ -n "$UNEXPECTED" ]; then
  echo "$UNEXPECTED"
  echo "  FAIL: nginx logged unexpected errors"; FAIL=1
else
  echo "  PASS: no unexpected nginx errors (only the deliberate duplicate-Content-Length rejection)"
fi

kill "$RR" 2>/dev/null; wait "$RR" 2>/dev/null || true

if [ "$FAIL" -ne 0 ]; then
  echo "### rr log (tail) ###"
  tail -40 /app/rr.log
fi

echo ""; [ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED ===" || echo "=== SOME CHECKS FAILED ==="
exit "$FAIL"
BASH

# =============================================================================
# 4. Dockerfile
# =============================================================================
cat > "$CTX/Dockerfile" <<'DOCKERFILE'
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli-trixie
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl nginx \
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

# =============================================================================
# 5. Build & run
# =============================================================================
prefetch_rr
build_and_run
