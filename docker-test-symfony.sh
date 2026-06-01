#!/usr/bin/env bash
set -euo pipefail

SYMFONY_VERSION="${1:?Usage: $0 <7.4|8.1>}"
IMAGE_TAG="rr-bundle-test-sf${SYMFONY_VERSION}"

echo "=== Building test image for Symfony ${SYMFONY_VERSION} ==="

docker build --build-arg SYMFONY_VERSION="${SYMFONY_VERSION}" \
  -t "${IMAGE_TAG}" \
  -f - . <<'DOCKERFILE'
FROM php:8.5-cli-trixie

ARG SYMFONY_VERSION

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install sockets \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

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

echo ""
echo "=== Running tests with Symfony ${SYMFONY_VERSION} ==="
docker run --rm "${IMAGE_TAG}"
