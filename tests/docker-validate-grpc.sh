#!/usr/bin/env bash
# Real end-to-end live validation of the gRPC worker (docs/specs/rr-grpc-worker.md IT-01..IT-08).
#
# Builds a minimal Symfony app on top of this bundle that IMPLEMENTS the bundle's committed
# tests/Grpc/Live/Generated/EchoInterface contract (stubs generated from tests/Grpc/Live/proto/
# echo.proto with protoc-gen-php-grpc v2025.1.15; regenerate only when the proto changes:
#   ./vendor/bin/rr download-protoc-binary && protoc --plugin=protoc-gen-php-grpc=./protoc-gen-php-grpc \
#     --php_out=tests/Grpc/Live/Generated --php-grpc_out=tests/Grpc/Live/Generated \
#     -I tests/Grpc/Live/proto tests/Grpc/Live/proto/echo.proto ).
# It runs inside a single Docker container against a REAL RoadRunner `grpc` pool, driven by
# grpcurl as the client. The assertions live in the bundle's PHPUnit live test
# (tests/Grpc/Live/GrpcLiveTest.php, #[Group('grpc-live')]) — this harness only PROVISIONS:
#
#   IT-01  Ping echoes the message + pid + x-echo response header
#   IT-02  Fail -> Code: InvalidArgument "boom"
#   IT-03  Crash (unhandled \RuntimeException) -> same worker pid answers the next Ping
#   IT-04  events fire with the caller's metadata (marker file over the shared FS)
#   IT-05  boot failure at BOTH sites (Runner kernel boot / worker routing table) answers
#          Code: Unavailable "Worker boot failed", and a fresh worker recovers once the flag clears
#   IT-07  a FULL Symfony profile per call (grpc:// url in the profiler index), with dump() in the
#          handler proving nothing leaks onto the goridge relay
#   IT-08  security: anonymous #[IsGranted] -> Unauthenticated; wrong token -> Invalid credentials;
#          live-token -> alice; required-mode -> Missing credentials
#
# Runs against each PHP version below — edit PHP_VERSIONS or pass one as an arg:
#   ./tests/docker-validate-grpc.sh            # all versions
#   ./tests/docker-validate-grpc.sh "8.4"      # only PHP 8.4
set -euo pipefail

# =============================================================================
# Config
# =============================================================================
PHP_VERSIONS=(8.4 8.5)
IMAGE_PREFIX="rr-bundle-grpc-validation"
GRPCURL_VERSION="1.9.3"

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
    echo "=== Running validation (PHP ${php}) ==="
    if ! docker run --rm "$tag"; then
      echo "!!! VALIDATION FAILED: PHP ${php}"; fail=1
    fi
  done
  return "$fail"
}

# =============================================================================
# 1. Build context — bundle -> /bundle (path repo); the app + the bundle's live
#    tests (for the generated Echo contract + the PHPUnit assertions) -> /app
# =============================================================================
CTX="$(mktemp -d)"
trap 'rm -rf "$CTX"' EXIT

echo "=== Preparing build context in $CTX ==="
cp composer.json "$CTX/composer.json"
cp -r src "$CTX/src"
cp -r config "$CTX/config"
mkdir -p "$CTX/app/src" "$CTX/app/public" "$CTX/app/config" "$CTX/app/var"

cp -r tests "$CTX/app/bundle-tests"
cp tests/Grpc/Live/proto/echo.proto "$CTX/app/echo.proto"

# =============================================================================
# 2. Test app — implements the committed Echo contract + security fixtures
# =============================================================================
cat > "$CTX/app/composer.json" <<'JSON'
{
    "require": {
        "php": ">=8.4",
        "fluffydiscord/roadrunner-symfony-bundle": "*",
        "spiral/roadrunner-grpc": "^3.6",
        "symfony/framework-bundle": "^7.4 || ^8",
        "symfony/security-bundle": "^7.4 || ^8",
        "symfony/runtime": "^7.4 || ^8",
        "symfony/var-dumper": "^7.4 || ^8",
        "symfony/stopwatch": "^7.4 || ^8",
        "symfony/yaml": "^7.4 || ^8"
    },
    "require-dev": {
        "phpunit/phpunit": "^13",
        "symfony/process": "^7.4 || ^8"
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

cat > "$CTX/app/src/GrpcEchoService.php" <<'PHP'
<?php

namespace App;

use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\CrashRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\EchoInterface;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\FailRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\PingResponse;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated\WhoAmIResponse;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\StatusCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GrpcEchoService implements EchoInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Security        $security,
        string                           $projectDir,
    ) {
        if (file_exists($projectDir . '/var/break-routing')) {
            throw new \RuntimeException('routing intentionally broken by var/break-routing');
        }
    }

    public function Ping(ContextInterface $ctx, PingRequest $in): PingResponse
    {
        $this->logger->info('handling Ping', ['message' => $in->getMessage()]);
        dump('dump-inside-grpc-handler');

        $headers = $ctx->getValue(ResponseHeaders::class);
        if ($headers instanceof ResponseHeaders) {
            $headers->set('x-echo', '1');
        }

        return new PingResponse()->setMessage($in->getMessage())->setPid(getmypid() ?: 0);
    }

    public function Fail(ContextInterface $ctx, FailRequest $in): PingResponse
    {
        throw GRPCException::create('boom', StatusCode::INVALID_ARGUMENT);
    }

    public function Crash(ContextInterface $ctx, CrashRequest $in): PingResponse
    {
        throw new \RuntimeException('crash');
    }

    #[IsGranted('ROLE_USER')]
    public function WhoAmI(ContextInterface $ctx, WhoAmIRequest $in): WhoAmIResponse
    {
        $userIdentifier = $this->security->getUser()?->getUserIdentifier() ?? 'anonymous';

        return new WhoAmIResponse()->setUser($userIdentifier);
    }
}
PHP

cat > "$CTX/app/src/GrpcEventMarkerListener.php" <<'PHP'
<?php

namespace App;

use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent;
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class GrpcEventMarkerListener
{
    private const MARKER_FILE = '/tmp/grpc-live-events.log';

    #[AsEventListener]
    public function onReceived(GrpcCallReceivedEvent $event): void
    {
        $metadataKeys = GrpcMetadata::fromContext($event->context)->getKeys();
        file_put_contents(self::MARKER_FILE, $event::class . ' ' . implode(',', $metadataKeys) . "\n", FILE_APPEND);
    }

    #[AsEventListener]
    public function onCompleted(GrpcCallCompletedEvent $event): void
    {
        file_put_contents(self::MARKER_FILE, $event::class . "\n", FILE_APPEND);
    }
}
PHP

cat > "$CTX/app/src/LiveTokenHandler.php" <<'PHP'
<?php

namespace App;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class LiveTokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        if ($accessToken === 'banned-token') {
            return new UserBadge('banned');
        }

        if ($accessToken !== 'live-token') {
            throw new BadCredentialsException('secret internal reason the client must never see');
        }

        return new UserBadge('alice');
    }
}
PHP

cat > "$CTX/app/src/LiveUserChecker.php" <<'PHP'
<?php

namespace App;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class LiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user->getUserIdentifier() === 'banned') {
            throw new DisabledException();
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
PHP

cat > "$CTX/app/src/Kernel.php" <<'PHP'
<?php

namespace App;

use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use RoadRunnerMicroKernelTrait {
        boot as private roadRunnerBoot;
    }

    public function boot(): void
    {
        if (file_exists($this->getProjectDir() . '/var/break-boot')) {
            throw new \RuntimeException('boot intentionally broken by var/break-boot');
        }

        $this->roadRunnerBoot();
    }

    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new \Symfony\Bundle\SecurityBundle\SecurityBundle();
        yield new \FluffyDiscord\RoadRunnerBundle\FluffyDiscordRoadRunnerBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'grpc-live-secret',
            'test' => false,
            'profiler' => ['enabled' => true, 'collect' => true, 'only_exceptions' => false],
            'php_errors' => ['log' => true],
        ]);

        $container->extension('security', [
            'providers' => [
                'app_users' => [
                    'memory' => ['users' => ['alice' => ['password' => null, 'roles' => ['ROLE_USER']], 'banned' => ['password' => null, 'roles' => ['ROLE_USER']]]],
                ],
            ],
            'firewalls' => [
                'dummy' => ['security' => false],
                'grpc' => ['stateless' => true, 'provider' => 'app_users', 'user_checker' => LiveUserChecker::class, 'pattern' => '^/never-matches-grpc$'],
            ],
        ]);

        $container->extension('fluffy_discord_road_runner', [
            'grpc' => [
                'tracing' => true,
                'security' => [
                    'enabled' => true,
                    'token_handler' => LiveTokenHandler::class,
                    'required' => file_exists($this->getProjectDir() . '/var/require-auth'),
                ],
            ],
        ]);

        $services = $container->services();
        $services->defaults()->autowire()->autoconfigure();
        $services->load('App\\', __DIR__ . '/');
        $services->get(GrpcEchoService::class)->arg('$projectDir', '%kernel.project_dir%');
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
# 3. RoadRunner config + entrypoint — start the grpc pool, then run
#    `phpunit --group grpc-live` (the live test holds the assertions)
# =============================================================================
cat > "$CTX/app/.rr.yaml" <<'YAML'
version: "3"
rpc:
    listen: "tcp://127.0.0.1:6001"
server:
    command: "php public/index.php"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'
        APP_ENV: "dev"
        APP_DEBUG: "1"
grpc:
    listen: "tcp://127.0.0.1:9001"
    proto:
        - "echo.proto"
    pool:
        num_workers: 1
logs:
    mode: production
    level: error
YAML

cat > "$CTX/app/entrypoint.sh" <<'BASH'
#!/usr/bin/env bash
set -uo pipefail
cd /app

export RR_GRPC_LIVE=1
export RR_GRPC_LIVE_FULL=1
export RR_GRPC_ADDRESS="127.0.0.1:9001"
export RR_GRPC_FLAG_DIR="/app/var"
export RR_GRPC_PROFILER_DIR="/app/var/cache/dev/profiler"
export RR_GRPC_RECYCLE_CMD="cd /app && rm -rf var/cache && ./rr reset -c /app/.rr.yaml"

dump_logs() {
  echo "----- rr serve log -----"; tail -n 120 /app/rr.log 2>/dev/null || true
}

echo "### Starting RoadRunner (grpc pool) ###"
./rr serve -c /app/.rr.yaml >/app/rr.log 2>&1 &
RR_PID=$!

ready=0
for i in $(seq 1 30); do
  if (exec 3<>/dev/tcp/127.0.0.1/9001) 2>/dev/null; then exec 3>&- 3<&- ; ready=1; break; fi
  if ! kill -0 "$RR_PID" 2>/dev/null; then echo "rr serve exited early"; dump_logs; exit 1; fi
  sleep 1
done
[ "$ready" -eq 1 ] && echo "  grpc listener up on :9001" || { echo "  FAIL: grpc listener never came up"; dump_logs; exit 1; }

echo "### Running the bundle live test suite (phpunit --group grpc-live) ###"
FAIL=0
php vendor/bin/phpunit bundle-tests/Grpc/Live --group grpc-live --testdox --colors=never || FAIL=1

[ "$FAIL" -ne 0 ] && dump_logs

kill "$RR_PID" 2>/dev/null; wait "$RR_PID" 2>/dev/null || true

echo ""
[ "$FAIL" -eq 0 ] && echo "=== ALL CHECKS PASSED (grpc-live) ===" || echo "=== SOME CHECKS FAILED ==="
exit "$FAIL"
BASH

# =============================================================================
# 4. Dockerfile — base + grpcurl (the client; no ext-grpc needed to SERVE gRPC)
# =============================================================================
cat > "$CTX/Dockerfile" <<DOCKERFILE
ARG PHP_VERSION=8.4
FROM php:\${PHP_VERSION}-cli-trixie
RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl \\
 && rm -rf /var/lib/apt/lists/* \\
 && docker-php-ext-install sockets \\
 && ARCH=\$(dpkg --print-architecture | sed 's/amd64/x86_64/; s/arm64/arm64/') \\
 && curl -fsSL "https://github.com/fullstorydev/grpcurl/releases/download/v${GRPCURL_VERSION}/grpcurl_${GRPCURL_VERSION}_linux_\${ARCH}.tar.gz" \\
    | tar -xz -C /usr/local/bin grpcurl
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json /bundle/composer.json
COPY src/ /bundle/src/
COPY config/ /bundle/config/
WORKDIR /app
COPY app/ /app/
RUN composer install --no-interaction --no-progress \\
 && { [ -f /app/rr ] || php vendor/bin/rr get-binary --location /app; } \\
 && chmod +x /app/rr /app/entrypoint.sh
CMD ["/app/entrypoint.sh"]
DOCKERFILE

# =============================================================================
# 5. Build & run
# =============================================================================
prefetch_rr
build_and_run
