#!/usr/bin/env bash
#
# Run the unit test suite across a matrix of PHP x Symfony versions in Docker.
#
# Unlike the docker-validate-*.sh harnesses, this one needs no test app: it builds the bundle itself,
# pins the symfony/* packages to each version, and runs `phpunit tests/`. The build context is the
# repo root directly (no temp dir) — only the Dockerfile is generated.
#
# Usage:
#   ./tests/docker-test-symfony.sh                 # full matrix (all PHP x all Symfony)
#   ./tests/docker-test-symfony.sh "8.4"           # only PHP 8.4, all Symfony
#   ./tests/docker-test-symfony.sh "8.4 8.5" "7.4" # narrow both axes
#
set -uo pipefail

# =============================================================================
# Config
# =============================================================================
PHP_VERSIONS=(8.4 8.5)        # official php:<ver>-cli-trixie images
SYMFONY_VERSIONS=(7.4 8.1)    # passed as ^<ver> constraints to the symfony/* packages
IMAGE_PREFIX="rr-bundle-test"

[ "${1:-}" ] && read -ra PHP_VERSIONS <<< "$1"        # first arg narrows the PHP axis
[ "${2:-}" ] && read -ra SYMFONY_VERSIONS <<< "$2"    # second arg narrows the Symfony axis
cd "$(dirname "$0")/.."                                # run from the repo root, wherever we're invoked from

# =============================================================================
# Dockerfile — the bundle + symfony/* pinned per build-arg, then `phpunit tests/`
# =============================================================================
DOCKERFILE="$(mktemp)"
trap 'rm -f "$DOCKERFILE"' EXIT

cat > "$DOCKERFILE" <<'DOCKERFILE'
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli-trixie

ARG SYMFONY_VERSION

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json ./
COPY src/ src/
COPY tests/ tests/
COPY config/ config/

RUN composer config minimum-stability dev \
 && composer config prefer-stable true \
 && composer require --no-update --no-interaction \
      "symfony/dependency-injection:^${SYMFONY_VERSION}" \
      "symfony/http-kernel:^${SYMFONY_VERSION}" \
      "symfony/psr-http-message-bridge:^${SYMFONY_VERSION}" \
      "symfony/runtime:^${SYMFONY_VERSION}" \
      "symfony/framework-bundle:^${SYMFONY_VERSION}" \
      "symfony/event-dispatcher:^${SYMFONY_VERSION}" \
      "symfony/expression-language:^${SYMFONY_VERSION}" \
      "symfony/mime:^${SYMFONY_VERSION}" \
 && composer update --prefer-dist --no-interaction --no-progress

CMD ["vendor/bin/phpunit", "tests/"]
DOCKERFILE

# =============================================================================
# Build & run the PHP x Symfony matrix
# =============================================================================
FAIL=0
for php in "${PHP_VERSIONS[@]}"; do
  for sf in "${SYMFONY_VERSIONS[@]}"; do
    tag="${IMAGE_PREFIX}-php${php}-sf${sf}"
    echo
    echo "=== Building test image: PHP ${php} / Symfony ${sf} ==="
    if ! docker build --build-arg PHP_VERSION="$php" --build-arg SYMFONY_VERSION="$sf" -t "$tag" -f "$DOCKERFILE" .; then
      echo "!!! BUILD FAILED: PHP ${php} / Symfony ${sf}"; FAIL=1; continue
    fi
    echo "=== Running tests: PHP ${php} / Symfony ${sf} ==="
    docker run --rm "$tag" || FAIL=1
  done
done

echo
[ "$FAIL" -eq 0 ] && echo "=== ALL COMBINATIONS PASSED ===" || echo "=== SOME COMBINATIONS FAILED ==="
exit "$FAIL"
