#!/usr/bin/env bash
# Run the unit test suite across a matrix of PHP x Symfony versions in Docker.
#
# Add/remove versions by editing the two lists below — nothing else needs to change.
#   PHP_VERSIONS     : official `php:<ver>-cli-trixie` images are used.
#   SYMFONY_VERSIONS : passed as `^<ver>` constraints to the symfony/* packages.
#
# Usage:
#   ./tests/docker-test-symfony.sh                 # full matrix (all PHP x all Symfony)
#   ./tests/docker-test-symfony.sh "8.4"           # only PHP 8.4, all Symfony
#   ./tests/docker-test-symfony.sh "8.4 8.5" "7.4" # narrow both axes
set -uo pipefail

PHP_VERSIONS=(8.4 8.5)
SYMFONY_VERSIONS=(7.4 8.1)

[ "${1:-}" ] && read -ra PHP_VERSIONS <<< "$1"
[ "${2:-}" ] && read -ra SYMFONY_VERSIONS <<< "$2"

# Build context is the repo root, regardless of where this script is invoked from.
cd "$(dirname "$0")/.."

FAIL=0
for PHP_VERSION in "${PHP_VERSIONS[@]}"; do
  for SYMFONY_VERSION in "${SYMFONY_VERSIONS[@]}"; do
    IMAGE_TAG="rr-bundle-test-php${PHP_VERSION}-sf${SYMFONY_VERSION}"

    echo ""
    echo "=== Building test image: PHP ${PHP_VERSION} / Symfony ${SYMFONY_VERSION} ==="
    if ! docker build \
          --build-arg PHP_VERSION="${PHP_VERSION}" \
          --build-arg SYMFONY_VERSION="${SYMFONY_VERSION}" \
          -t "${IMAGE_TAG}" \
          -f - . <<'DOCKERFILE'
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
    then
      echo "!!! BUILD FAILED: PHP ${PHP_VERSION} / Symfony ${SYMFONY_VERSION}"
      FAIL=1
      continue
    fi

    echo "=== Running tests: PHP ${PHP_VERSION} / Symfony ${SYMFONY_VERSION} ==="
    docker run --rm "${IMAGE_TAG}" || FAIL=1
  done
done

echo ""
[ "$FAIL" -eq 0 ] && echo "=== ALL COMBINATIONS PASSED ===" || echo "=== SOME COMBINATIONS FAILED ==="
exit "$FAIL"
