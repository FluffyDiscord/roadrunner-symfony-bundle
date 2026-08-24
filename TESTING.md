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
Expected: PHPStan **0 errors**; PHPUnit **all green, ~18 skipped**. The skips are the `*-live`
groups and Symfony-version-gated tests — they only run inside the Docker scripts below. This is normal.

The PHPUnit run is type-enforced: `typephp/typephp` rewrites `src/**` at include time and checks
every `@param`/`@return`/`@var` contract at runtime — that is why it takes ~15s. Config:
`typephp.php` (root); `tests/**` is excluded (TypePHP cannot resolve PHPUnit mock intersection
types or group-namespace aliases).

```bash
TYPEPHP_DISABLE=1 php vendor/bin/phpunit tests   # unenforced, ~0.5s — triage a suspect failure
```

- `tests/TypePhpEnforcementTest.php` fails if enforcement is off — needed because TypePHP's
  tooling opt-out is a **substring** match over argv and the script path: `--filter
  …Directory…` disables it (`rector` ⊂ `directory`), as does a checkout path containing
  `composer`, `phpstan`, `pint` or `mago` (upstream typephp-php/typephp#47).
- An **anonymous class is not a valid `class-string`** to TypePHP (upstream #46): a test double
  whose `::class` reaches a `class-string` contract must be a named fixture — see
  `tests/Grpc/Fixtures/FaultingEchoService.php`, `tests/Temporal/Fixtures/DefaultQueueWorker.php`.

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
./tests/docker-validate-grpc.sh                 # gRPC worker (real RR grpc pool; grpcurl as the client)
./tests/docker-validate-doctrine-preconnect.sh  # PostgreSQL boot preconnect (real PostgreSQL)
./tests/docker-validate-error-pages.sh          # graceful die()/exit()/fatal handling

./tests/docker-validate-temporal.sh "8.4"       # e.g. single PHP version
```
- `docker-validate-all.sh` runs `grpc-live` basic flows only (prod-mode shared app); `docker-validate-grpc.sh` covers boot-failure flag flips, the profiler and required-auth.
- `docker-validate-all.sh` excludes `doctrine-preconnect-live` (no PostgreSQL in that image); run
  `docker-validate-doctrine-preconnect.sh` for it.
- Speed up the build by reusing a local `rr` binary: `RR_BIN=$(command -v rr) ./tests/docker-validate-*.sh`.

## `@group` map (skipped on host → which script runs it)
| Group | Script |
|-------|--------|
| `jobs-live` | `docker-validate-jobs.sh`, `docker-validate-all.sh` |
| `temporal-live` | `docker-validate-temporal.sh`, `docker-validate-all.sh` |
| `grpc-live` | `docker-validate-grpc.sh` (full), `docker-validate-all.sh` (basic flows — the flag-flip/profiler cases need `RR_GRPC_LIVE_FULL`) |
| `doctrine-preconnect-live` | `docker-validate-doctrine-preconnect.sh` |
