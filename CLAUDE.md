# roadrunner-symfony-bundle

RoadRunner runtime bundle for Symfony (HTTP + Centrifugo + Jobs + Temporal workers).

## Quality checks

Run both before committing. As of the latest cleanup, **both are green**: PHPStan

### Benchmarks — `tests/docker-bench.sh`

RPS + conversion-attribution harness. Runs wrk inside docker against a raw-PHP
ceiling worker and the bundle with both `http.request_factory` strategies; numbers are
session-relative — compare only within one run.

### Static analysis — PHPStan (level `max`)

```bash
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

- Config: `phpstan.neon` — level `max`, analyses `src` only, with `phpstan-symfony`.
- **`--memory-limit=1G` is required.** The default 128 MB crashes the parallel
  worker with "reached configured PHP memory limit" and reports an incomplete result.
- Do **not** silence errors with `@phpstan-ignore`, baseline entries, `assert()`,
  inline `@var`, or type casts/widening added purely to quiet the analyser — fix the
  underlying type instead (validate `mixed` from framework APIs with `is_*`/`is_array`
  guards, type routing tables via `@phpstan-type`, null-check nullable containers).

### Tests — PHPUnit 13

```bash
php vendor/bin/phpunit tests
```

- **There is no `phpunit.xml`** — you must pass the `tests` directory explicitly;
  a bare `php vendor/bin/phpunit` finds no configuration and runs nothing.
- Final classes (`RoadRunner\Centrifugo\CentrifugoWorker`, the `Request\*` types,
  `respond()`/`error()`/`disconnect()`) cannot be mocked. Worker tests instead build
  real fixtures around a mocked goridge `WorkerInterface` and drive the loop through
  `waitRequest()` / `registerShutdown()` / `logError()` seams on testable subclasses.

## Layout

- `src/Worker/` — `HttpWorker`, `CentrifugoWorker`, `JobsWorker` (graceful error handling:
  one frame per request, STDERR/Sentry logging, `register_shutdown_function` rescue for
  die/exit/fatal). See `docs/specs/graceful-error-handling.md`. The Jobs (queue consumer)
  worker — ack-on-success / nack-with-requeue-on-failure — is specced in
  `docs/specs/rr-jobs-worker.md` and registered under `Mode::MODE_JOBS`.
- `src/Job/` — typed message bus over RR Jobs built on **Symfony Messenger** (additive on top of
  `JobsRunEvent`): `#[AsJob]` (producer attribute) + `JobDispatcher`, `JobEnvelope` (wire contract:
  `x-job-class` / `x-job-serializer` headers), igbinary/Native (PHP serialize) + optional Symfony
  serializers. On consume, `JobRoutingListener` deserializes and dispatches the message into
  `MessageBusInterface` (passing the RR task via a `HandlerArgumentsStamp`); handlers are plain
  `#[AsMessageHandler]`. Specced in `docs/specs/jobs-message-bus.md`. `symfony/messenger` and
  `symfony/serializer` are `require-dev` + `suggest` only.
- `src/Factory/ServerParamsFactory.php` — builds the Symfony `Request` server bag from the
  RoadRunner request alone (method, URI, protocol, remote address, host, `HTTP_*` headers). The
  worker's boot-time `$_SERVER` is never mixed in, so the process environment (and an `HTTP_PROXY`
  env var posing as a request header) cannot reach the request. The `$_SERVER` superglobal itself is
  left at its boot-time state — read request data off the `Request`.
- `src/Http/InformationalHeaders.php` — tracks the header values already emitted in `1xx` (Early
  Hints) frames so they are not repeated in the final response. RoadRunner's Go handler `Add`s
  worker headers and keeps `1xx` headers per RFC 8297, so re-sending the bag duplicates them on the
  wire. Statics are forced here: the `headers_send()` polyfill is a global function with no DI
  access (same constraint as `HttpWorker::$currentHttpWorker`). Live-tested by
  `tests/docker-validate-early-hints.sh`.
- `src/ErrorHandler/MinimalErrorPage.php` — dependency-free fallback error page.
- `src/ErrorHandler/FatalError.php` — filters `error_get_last()` to genuinely fatal types, so a stale
  deprecation is never reported as the cause of a `die`/`exit`.
- `src/ErrorHandler/DumpCapture.php` — chains onto `VarDumper`'s handler to record where the last
  `dump()`/`dd()` ran (PHP records nothing for `exit`), so the rescue page can name and IDE-link it.
  Specced in `docs/specs/dump-capture.md`; live-tested by the `/dd` case in
  `tests/docker-validate-error-pages.sh`.
- `src/EventListener/CentrifugoEventRouter.php` + `src/DependencyInjection/Compiler/CentrifugoRouterPass.php`
  — compile-time routing table for `#[AsCentrifugoChannelListener]` / `#[AsCentrifugoRpcListener]`.
- Optional **distributed locks**: when `roadrunner-php/symfony-lock-driver` is installed, `config/services.php`
  wires a Symfony `LockFactory` / `PersistingStoreInterface` onto RR's Lock plugin over the bundle's RPC
  (no `src/` class of our own — pure DI wiring, guarded by `class_exists(RoadRunnerStore::class)`).
