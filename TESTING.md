# Testing

## Requirements
- PHP >= 8.4, Composer
- `composer install`
- Docker — only for the `tests/docker-*.sh` scripts

## Before committing (both must pass)
```bash
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G   # level max; --memory-limit=1G is required
php vendor/bin/phpunit tests                                     # pass `tests` explicitly — there is no phpunit.xml
```
Expected: PHPStan **0 errors**; PHPUnit **all green, ~11 skipped**. The skips are the `*-live`
groups and Symfony-version-gated tests — they only run inside the Docker scripts below. This is normal.

## Run one test / file
```bash
php vendor/bin/phpunit tests/Doctrine/DoctrinePreconnectListenerTest.php
php vendor/bin/phpunit tests --filter testPdoPostgresConnectionIsPreconnected
```

## PHP × Symfony matrix (Docker)
Runs `phpunit tests` across versions:
```bash
./tests/docker-test-symfony.sh                 # full matrix
./tests/docker-test-symfony.sh "8.4"           # one PHP version
./tests/docker-test-symfony.sh "8.4 8.5" "7.4" # narrow PHP and Symfony
```

## Live end-to-end (Docker, real services)
Each builds a Symfony app on the bundle, starts the real dependency + `rr serve`, and runs the
matching `@group *-live` test as the assertion. Optional arg = PHP version (default: all in the script).
```bash
./tests/docker-validate-all.sh                  # whole suite in one container: real RR http+jobs+temporal
./tests/docker-validate-jobs.sh                 # Jobs message bus + queue-consumer worker
./tests/docker-validate-temporal.sh             # Temporal (real dev server; image installs ext-grpc)
./tests/docker-validate-doctrine-preconnect.sh  # PostgreSQL boot preconnect (real PostgreSQL)
./tests/docker-validate-error-pages.sh          # graceful die()/exit()/fatal handling

./tests/docker-validate-temporal.sh "8.4"       # e.g. single PHP version
```
- `docker-validate-all.sh` excludes `doctrine-preconnect-live` (no PostgreSQL in that image); run
  `docker-validate-doctrine-preconnect.sh` for it.
- Speed up the build by reusing a local `rr` binary: `RR_BIN=$(command -v rr) ./tests/docker-validate-*.sh`.

## `@group` map (skipped on host → which script runs it)
| Group | Script |
|-------|--------|
| `jobs-live` | `docker-validate-jobs.sh`, `docker-validate-all.sh` |
| `temporal-live` | `docker-validate-temporal.sh`, `docker-validate-all.sh` |
| `doctrine-preconnect-live` | `docker-validate-doctrine-preconnect.sh` |
