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

The PHPUnit run is type-enforced: `typephp/typephp` (pinned `0.6.2`) rewrites `src/**` at include
time and checks every `@param`/`@return`/`@var` contract at runtime — that is why it takes ~9s.
Config: `typephp.php` (root). `include` names `src/**` and nothing else, so `tests/**` is never
rewritten (TypePHP cannot resolve PHPUnit mock intersection types or group-namespace aliases).

```bash
TYPEPHP_DISABLE=1 php vendor/bin/phpunit tests   # unenforced, ~0.5s — triage a suspect failure
```

- `tests/TypePhpEnforcementTest.php` fails if enforcement is off, so a run that silently stops
  checking cannot pass as green. `0.6.0` made that easy to trip: its tooling opt-out was a
  substring match over argv and the script path, so `--filter …Directory…` disabled it
  (`rector` ⊂ `directory`), as did a checkout path containing `composer` or `phpstan`. `0.6.2`
  matches the argv[0] basename exactly (upstream typephp-php/typephp#47) — keep the guard anyway,
  it also catches a stray `TYPEPHP_DISABLE` in the environment.
- Test doubles reaching a `class-string` contract may be anonymous again as of `0.6.2` (upstream
  #46). The named fixtures under `tests/Grpc/Fixtures/` and `tests/Temporal/Fixtures/` stay — they
  are reused across tests — but a new one-off double no longer has to be a named class.

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
Symfony is the only axis that moves: `composer.lock` is copied into the image and the update is
restricted to `symfony/*`, so every other dependency sits at the version the host runs. A red cell
therefore means a real PHP/Symfony incompatibility, not an unrelated package that drifted
overnight. To test against current upstream instead, `composer update` on the host and re-run.

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
